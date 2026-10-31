<?php

namespace App\Services\Videos;

use App\Models\Clinique;
use App\Models\FactureProprietaire;
use App\Models\PlusPoolMonth;
use App\Models\PlusPoolShare;
use App\Models\User;
use App\Paiements\ErreurPaiement;
use App\Paiements\PasserellePaiement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
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
            // Une vidéo dont le vendeur a effacé son compte ne prend rien : sa part revient aux autres vendeurs
            ->where(fn ($seller) => $seller->whereNotNull('videos.seller_user_id')->orWhereNotNull('videos.seller_clinique_id'))
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
        Cache::lock('plus-pool-pay', 21600)->block($this->lockWait, fn () => $this->payUnlocked($month));
    }

    private function payUnlocked(CarbonImmutable $month): void
    {
        $month = $month->setTimezone('Europe/Zurich')->startOfMonth();
        $yearEnd = (int) $month->format('n') === 12;
        $unpaid = PlusPoolShare::query()->with('month')->whereNull('paid_at')->where('earned_cents', '>', 0)
            ->where(fn ($seller) => $seller->whereNotNull('seller_user_id')->orWhereNotNull('seller_clinique_id'))->get()
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
            try {
                $this->transfer($seller, $shares, $account, $month->format('Y-m'));
            } catch (Throwable $e) {
                // Une panne : la part attend le mois suivant, les autres vendeurs passent
                report($e);
            }
        }
    }

    /**
     * Le solde d'un vendeur qui efface son compte (décision de Jim,
     * 31.10.2026) : tout ce qui est déjà calculé lui est versé maintenant,
     * même sous 10 CHF, avant que son compte de paiement se ferme. Les jours
     * du mois en cours ne se calculent qu'à la fin du mois : ils ne sont pas
     * versés. Un versement encore en route, ou une panne : une exception,
     * l'effacement attend la nuit suivante. Sans compte de paiement prêt,
     * rien ne peut être versé : c'est signalé.
     */
    public function settleSeller(User $user): void
    {
        Cache::lock('plus-pool-pay', 21600)->block($this->lockWait, function () use ($user): void {
            $seller = 'user:'.$user->id;
            // Le mois passé pas encore calculé (effacement le 1er, avant le passage de 05:00) : calculé
            // maintenant, sa part en fait partie
            $previous = CarbonImmutable::now('Europe/Zurich')->subMonthNoOverflow()->startOfMonth();
            if (! PlusPoolMonth::query()->where('month', $previous->format('Y-m'))->exists()) {
                $this->compute($previous);
            }
            $shares = PlusPoolShare::query()->with('month')->whereNull('paid_at')->where('earned_cents', '>', 0)->where('seller_user_id', $user->id)->get();
            // Rattachées à son compte de paiement : ce qui ne peut pas partir ce soir (refus, compte pas
            // prêt) part avant la fermeture du compte, par la tâche de nuit (payDeparted)
            if ($user->stripe_account_id !== null) {
                PlusPoolShare::query()->whereIn('id', $shares->modelKeys())->update(['stripe_account_id' => $user->stripe_account_id]);
            }
            if ($shares->contains(fn (PlusPoolShare $share) => $share->payout_group !== null && $share->updated_at > now()->subHour())) {
                throw new RuntimeException("Versement de la cagnotte du vendeur {$user->id} encore en route : effacement repris la nuit suivante");
            }
            $shares = $this->settleLostPayouts($shares);
            if ($shares->isEmpty()) {
                return;
            }
            $account = $this->account($seller);
            if ($account === null) {
                // Pas encore prêt : la tâche de nuit retente avant de fermer le compte
                return;
            }
            try {
                $this->transfer($seller, $shares, $account, now('Europe/Zurich')->format('Y-m'));
            } catch (ErreurPaiement $e) {
                if ($e->estPassagere() || $e->cleRefusee()) {
                    throw $e;
                }
                // Refusé net (le compte ne reçoit pas encore de virements, solde de la plateforme insuffisant) :
                // l'effacement continue, la tâche de nuit retente avant de fermer le compte
                report($e);
            }
        });
    }

    /**
     * Ce qui reste dû au compte d'un vendeur effacé (versement refusé, ou
     * compte pas prêt le soir de l'effacement) : versé avant la fermeture du
     * compte. Rend vrai quand plus rien n'est dû ; un refus ou un versement
     * encore en route : faux, la nuit suivante retente.
     */
    public function payDeparted(string $account): bool
    {
        return Cache::lock('plus-pool-pay', 21600)->block($this->lockWait, function () use ($account): bool {
            $shares = PlusPoolShare::query()->with('month')->whereNull('paid_at')->where('earned_cents', '>', 0)
                ->whereNull('seller_user_id')->whereNull('seller_clinique_id')->where('stripe_account_id', $account)->get();
            if ($shares->contains(fn (PlusPoolShare $share) => $share->payout_group !== null && $share->updated_at > now()->subHour())) {
                return false;
            }
            $shares = $this->settleLostPayouts($shares);
            if ($shares->isEmpty()) {
                return true;
            }
            try {
                $this->transfer('departed:'.$account, $shares, $account, now('Europe/Zurich')->format('Y-m'));
            } catch (ErreurPaiement $e) {
                if ($e->estPassagere() || $e->cleRefusee()) {
                    throw $e;
                }

                return false;
            }

            return true;
        });
    }

    /**
     * Verse ces parts. Le groupe est noté avant l'appel : si la réponse se
     * perd, un passage suivant retrouve le versement au lieu de le refaire.
     *
     * @param  Collection<int, PlusPoolShare>  $shares
     */
    private function transfer(string $seller, Collection $shares, string $account, string $month): void
    {
        $group = 'plus-pool-'.str_replace(':', '-', $seller).'-'.Str::lower((string) Str::ulid());
        $this->sentThisRun[$group] = true;
        PlusPoolShare::query()->whereIn('id', $shares->modelKeys())->update(['payout_group' => $group, 'stripe_account_id' => $account]);
        // Recevoir des virements : demandé à chaque fois (sans effet si c'est déjà fait)
        $this->gateway->pourCompte(null)->demanderVirementsRecus($account);
        $transfer = $this->gateway->pourCompte(null)->transferer($account, (int) $shares->sum('earned_cents'), $group, [
            'objet' => 'cagnotte_plus', 'seller' => $seller, 'month' => $month,
        ], $group);
        PlusPoolShare::query()->whereIn('id', $shares->modelKeys())->update(['paid_at' => now(), 'transfer_id' => $transfer]);
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
            // Envoyé il y a moins d'une heure (mois rattrapés à la suite, passage lancé à la main) :
            // le prestataire ne le montre peut-être pas encore ; il se cherche au passage suivant
            if (isset($this->sentThisRun[$group]) || $sent->max('updated_at') > now()->subHour()) {
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
