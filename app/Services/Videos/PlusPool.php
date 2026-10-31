<?php

namespace App\Services\Videos;

use App\Models\Clinique;
use App\Models\FactureProprietaire;
use App\Models\PlusPoolMonth;
use App\Models\PlusPoolShare;
use App\Models\User;
use App\Paiements\PasserellePaiement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * La cagnotte Plus des vidéos (décisions de Jim, 31.10.2026) : chaque
 * mois, la moitié des revenus nets de Plus (factures payées, moins les
 * frais du prestataire), partagée entre les vendeurs selon les vues comptées
 * grâce à Plus. Versée dès que ce qui est dû atteint 10 CHF, et au plus
 * tard à la fin de l'année (la cagnotte de décembre verse tout). Les
 * centimes qui ne se partagent pas restent à Veterinary.
 */
class PlusPool
{
    public const SHARE_RATIO = 0.5;

    public const MIN_PAYOUT_CENTS = 1000;

    public function __construct(private PasserellePaiement $gateway) {}

    /** Calcule la cagnotte du mois une seule fois, puis verse ce qui doit l'être. */
    public function run(CarbonImmutable $month): PlusPoolMonth
    {
        $month = $month->setTimezone('Europe/Zurich')->startOfMonth();
        $pool = PlusPoolMonth::query()->where('month', $month->format('Y-m'))->first() ?? $this->compute($month);
        $this->pay($month);

        return $pool;
    }

    public function compute(CarbonImmutable $month): PlusPoolMonth
    {
        $month = $start = $month->setTimezone('Europe/Zurich')->startOfMonth();
        $end = $start->addMonthNoOverflow();
        [$from, $to] = [$start->utc(), $end->utc()];

        // Plus se paie à l'année : chaque facture compte pour les jours du mois
        // qu'elle couvre (1/12 par mois), sinon les mois de renouvellement
        // prendraient tout. Seules les factures payées avant la fin du mois comptent.
        $net = 0.0;
        $invoices = FactureProprietaire::query()
            ->where('statut', 'payee')->where('paye_le', '<', $to)
            ->where('metadonnees->type', 'abonnement')->where('metadonnees->formule', 'plus')
            ->whereDate('periode_debut', '<', $end->toDateString())->whereDate('periode_fin', '>', $start->toDateString())
            ->get(['id', 'montant_ht', 'montant', 'stripe_paiement_id', 'periode_debut', 'periode_fin']);
        foreach ($invoices as $invoice) {
            $periodStart = CarbonImmutable::parse($invoice->periode_debut->toDateString(), 'Europe/Zurich');
            $periodEnd = CarbonImmutable::parse($invoice->periode_fin->toDateString(), 'Europe/Zurich');
            $days = $periodStart->diffInDays($periodEnd);
            $overlap = max(0, ($periodEnd < $end ? $periodEnd : $end)->diffInDays($periodStart > $start ? $periodStart : $start, true));
            if ($days <= 0 || $overlap <= 0) {
                continue;
            }
            $paid = max(0, (int) round(((float) ($invoice->montant_ht ?? $invoice->montant)) * 100) - $this->fee($invoice->stripe_paiement_id, (int) round(((float) ($invoice->montant_ht ?? $invoice->montant)) * 100)));
            $net += $paid * min(1, $overlap / $days);
        }
        $net = (int) floor($net);
        $poolCents = (int) floor($net * self::SHARE_RATIO);

        $views = DB::table('video_views')
            ->join('videos', 'videos.id', '=', 'video_views.video_id')
            ->where('video_views.counted_via_plus', true)
            ->where('video_views.counted_at', '>=', $from)->where('video_views.counted_at', '<', $to)
            ->groupBy('videos.seller_user_id', 'videos.seller_clinique_id')
            ->get(['videos.seller_user_id', 'videos.seller_clinique_id', DB::raw('count(*) as views')]);
        $total = (int) $views->sum('views');

        return DB::transaction(function () use ($month, $net, $poolCents, $views, $total): PlusPoolMonth {
            $row = PlusPoolMonth::query()->create([
                'month' => $month->format('Y-m'), 'net_revenue_cents' => $net, 'pool_cents' => $poolCents,
                'views' => $total, 'computed_at' => now(),
            ]);
            foreach ($views as $seller) {
                $row->shares()->create([
                    'seller_user_id' => $seller->seller_user_id,
                    'seller_clinique_id' => $seller->seller_clinique_id,
                    'views' => (int) $seller->views,
                    'earned_cents' => $total > 0 ? (int) floor($poolCents * $seller->views / $total) : 0,
                ]);
            }

            return $row;
        });
    }

    /** Verse à chaque vendeur ce qui lui est dû : dès 10 CHF, ou tout en fin d'année. */
    public function pay(CarbonImmutable $month): void
    {
        $month = $month->setTimezone('Europe/Zurich')->startOfMonth();
        $yearEnd = (int) $month->format('n') === 12;
        $unpaid = PlusPoolShare::query()->with('month')->whereNull('paid_at')->where('earned_cents', '>', 0)->get()
            ->groupBy(fn (PlusPoolShare $share) => $share->seller_user_id !== null ? 'user:'.$share->seller_user_id : 'clinique:'.$share->seller_clinique_id);

        foreach ($unpaid as $seller => $shares) {
            $amount = (int) $shares->sum('earned_cents');
            // Une part d'une année passée (versement de décembre raté) se verse toujours, même sous 10 CHF
            $fromPastYear = $shares->contains(fn (PlusPoolShare $share) => substr((string) $share->month?->month, 0, 4) < $month->format('Y'));
            if ($amount < self::MIN_PAYOUT_CENTS && ! $yearEnd && ! $fromPastYear) {
                continue;
            }
            $account = $this->account($seller);
            if ($account === null) {
                // Pas encore de compte prêt : versé dès qu'il le sera
                continue;
            }
            try {
                // Recevoir des virements : demandé à chaque fois (sans effet si c'est déjà fait)
                $this->gateway->pourCompte(null)->demanderVirementsRecus($account);
                $transfer = $this->gateway->pourCompte(null)->transferer($account, $amount, 'plus-pool-'.$seller.'-'.$shares->pluck('id')->implode('-'), [
                    'objet' => 'cagnotte_plus', 'seller' => $seller, 'month' => $month->format('Y-m'),
                ]);
            } catch (Throwable $e) {
                // Une panne : la part attend le mois suivant, les autres vendeurs passent
                report($e);

                continue;
            }
            PlusPoolShare::query()->whereIn('id', $shares->modelKeys())->update(['paid_at' => now(), 'transfer_id' => $transfer, 'stripe_account_id' => $account]);
        }
    }

    /** Les frais du prestataire sur un paiement, relus une seule fois (la facture compte douze mois). */
    private function fee(?string $intentId, int $amount): int
    {
        if ($intentId === null) {
            return 0;
        }
        try {
            $fee = Cache::rememberForever('plus-pool-fee:'.$intentId, fn () => $this->gateway->pourCompte(null)->fraisIntention($intentId));
        } catch (Throwable $e) {
            report($e);
            $fee = null;
        }

        // Illisibles : estimés au tarif des cartes en Suisse (2,9 % + 30 centimes), pour ne pas gonfler la cagnotte
        return $fee !== null ? (int) $fee : (int) round($amount * 0.029) + 30;
    }

    private function account(string $seller): ?string
    {
        [$kind, $id] = explode(':', $seller);
        if ($kind === 'user') {
            $user = User::query()->find((int) $id);

            return $user?->stripe_account_ready_at !== null ? $user->stripe_account_id : null;
        }
        $societe = Clinique::withoutGlobalScopes()->find((int) $id)?->societe;

        return $societe?->paiements_actifs ? $societe->stripe_compte_id : null;
    }
}
