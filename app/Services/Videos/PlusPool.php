<?php

namespace App\Services\Videos;

use App\Models\Clinique;
use App\Models\FactureProprietaire;
use App\Models\PlusPoolMonth;
use App\Models\PlusPoolShare;
use App\Models\User;
use App\Paiements\PasserellePaiement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
    /** Secondes d'attente d'un autre passage en cours */
    public int $lockWait = 120;

    /** @var array<string, true> Groupes envoyés pendant ce passage : leur réponse perdue se cherche au passage suivant, pas tout de suite */
    private array $sentThisRun = [];

    public function pay(CarbonImmutable $month): void
    {
        // Un lancement à la main pendant le passage planifié : l'un attend l'autre, rien ne se verse deux fois
        Cache::lock('plus-pool-pay', 3600)->block($this->lockWait, fn () => $this->payUnlocked($month));
    }

    private function payUnlocked(CarbonImmutable $month): void
    {
        $month = $month->setTimezone('Europe/Zurich')->startOfMonth();
        $yearEnd = (int) $month->format('n') === 12;
        $unpaid = PlusPoolShare::query()->with('month')->whereNull('paid_at')->where('earned_cents', '>', 0)->get()
            ->groupBy(fn (PlusPoolShare $share) => $share->seller_user_id !== null ? 'user:'.$share->seller_user_id : 'clinique:'.$share->seller_clinique_id);

        foreach ($unpaid as $seller => $shares) {
            // Un versement parti dont la réponse s'est perdue : retrouvé par son groupe, il ne se refait pas
            try {
                $shares = $this->settleLostPayouts($shares);
            } catch (Throwable $e) {
                report($e);

                continue;
            }
            if ($shares->isEmpty()) {
                continue;
            }
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
            // Noté avant l'appel : si la réponse se perd, le mois suivant retrouve le versement au lieu de le refaire
            $group = 'plus-pool-'.str_replace(':', '-', $seller).'-'.Str::lower((string) Str::ulid());
            $this->sentThisRun[$group] = true;
            PlusPoolShare::query()->whereIn('id', $shares->modelKeys())->update(['payout_group' => $group, 'stripe_account_id' => $account]);
            try {
                // Recevoir des virements : demandé à chaque fois (sans effet si c'est déjà fait)
                $this->gateway->pourCompte(null)->demanderVirementsRecus($account);
                $transfer = $this->gateway->pourCompte(null)->transferer($account, $amount, $group, [
                    'objet' => 'cagnotte_plus', 'seller' => $seller, 'month' => $month->format('Y-m'),
                ], $group);
            } catch (Throwable $e) {
                // Une panne : la part attend le mois suivant, les autres vendeurs passent
                report($e);

                continue;
            }
            PlusPoolShare::query()->whereIn('id', $shares->modelKeys())->update(['paid_at' => now(), 'transfer_id' => $transfer]);
        }
    }

    /**
     * Les parts déjà envoyées sous un groupe, sans réponse : versées pour de
     * bon (le prestataire connaît le groupe), elles sont marquées payées ;
     * jamais arrivées, elles redeviennent à verser. Rend ce qui reste à verser.
     *
     * @param  Collection<int, PlusPoolShare>  $shares
     * @return Collection<int, PlusPoolShare>
     */
    private function settleLostPayouts(Collection $shares): Collection
    {
        foreach ($shares->whereNotNull('payout_group')->groupBy('payout_group') as $group => $sent) {
            // Envoyé il y a quelques secondes (mois rattrapés à la suite) : le prestataire ne le montre peut-être pas encore
            if (isset($this->sentThisRun[$group])) {
                $shares = $shares->reject(fn (PlusPoolShare $share) => $share->payout_group === $group)->values();

                continue;
            }
            $transfer = $this->gateway->pourCompte(null)->versementDuGroupe((string) $group);
            if ($transfer !== null) {
                PlusPoolShare::query()->whereIn('id', $sent->modelKeys())->update(['paid_at' => now(), 'transfer_id' => $transfer]);
                $shares = $shares->reject(fn (PlusPoolShare $share) => $share->payout_group === $group)->values();
            } else {
                PlusPoolShare::query()->whereIn('id', $sent->modelKeys())->update(['payout_group' => null]);
                $sent->each(fn (PlusPoolShare $share) => $share->payout_group = null);
            }
        }

        return $shares;
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
