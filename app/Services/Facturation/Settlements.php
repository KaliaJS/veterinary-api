<?php

namespace App\Services\Facturation;

use App\Enums\MoyenPaiement;
use App\Enums\SettlementMethod;
use App\Enums\StatutFacture;
use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\Facture;
use App\Models\Paiement;
use App\Models\Settlement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Les règlements des factures (demande de Jim, 09.11.2026) : chaque
 * paiement reçu est une ligne datée du jour où l'argent est arrivé ; une
 * erreur s'annule par une ligne négative, un remboursement aussi, rien ne
 * s'efface. Le statut de la facture suit : payée quand ses règlements
 * atteignent son total (datée du règlement qui la solde), de nouveau due
 * quand une ligne négative la fait repasser dessous.
 *
 * Toujours appelé sous le verrou de la facture (EncaissementFacture,
 * PaiementsCaisse) ou en prenant ce verrou ici.
 */
class Settlements
{
    /**
     * Un paiement reçu. Le statut de la facture suit ; le stock est l'affaire
     * de l'appelant (EncaissementFacture).
     */
    public function record(Facture $facture, SettlementMethod $method, float $amount, ?CarbonImmutable $receivedOn = null, ?User $by = null, ?string $reference = null, ?Paiement $paiement = null): Settlement
    {
        $clinique = $this->clinique($facture);
        $settlement = $this->create($facture, [
            'method' => $method,
            'amount' => round($amount, 2),
            'received_on' => ($receivedOn ?? CarbonImmutable::parse(aujourdhui($clinique)->toDateString()))->toDateString(),
            'reference' => $reference !== null && trim($reference) !== '' ? trim($reference) : null,
            'paiement_id' => $paiement?->id,
            'recorded_by_id' => $by?->id,
        ]);
        $this->apply($facture, $settlement);

        return $settlement;
    }

    /**
     * Annule un règlement par une ligne négative du même montant (règle de
     * Jim : rien ne s'efface), avec le motif, qui et quand. Une ligne Stripe
     * se rembourse chez Stripe ; l'acompte en ligne se rend depuis le
     * rendez-vous.
     */
    public function reverse(Facture $facture, Settlement $settlement, string $reason, ?User $by): Settlement
    {
        return DB::transaction(function () use ($facture, $settlement, $reason, $by): Settlement {
            Facture::query()->withoutGlobalScopes()->whereKey($facture->id)->lockForUpdate()->first();
            $tenu = Settlement::query()->withoutGlobalScopes()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();

            if ($tenu->facture_id !== $facture->id) {
                throw ValidationException::withMessages(['settlement' => 'Ce règlement n’est pas celui de cette facture.']);
            }
            if ($tenu->reverses_id !== null || (float) $tenu->amount <= 0) {
                throw ValidationException::withMessages(['settlement' => 'Une annulation ne s’annule pas.']);
            }
            if ($tenu->reversal()->exists()) {
                throw ValidationException::withMessages(['settlement' => 'Ce règlement est déjà annulé.']);
            }
            if ($tenu->method === SettlementMethod::Acompte) {
                throw ValidationException::withMessages(['settlement' => 'L’acompte payé en ligne se rend depuis le rendez-vous.']);
            }
            if ($tenu->paiement_id !== null) {
                throw ValidationException::withMessages(['settlement' => 'Un paiement passé par le terminal ou TWINT se rembourse chez Stripe, depuis le compte de paiement.']);
            }

            $facture->refresh();
            $ligne = $this->create($facture, [
                'method' => $tenu->method,
                'amount' => -round((float) $tenu->amount, 2),
                'received_on' => aujourdhui($this->clinique($facture))->toDateString(),
                'reverses_id' => $tenu->id,
                'reason' => trim($reason),
                'recorded_by_id' => $by?->id,
            ]);
            $this->apply($facture, $ligne);

            return $ligne;
        });
    }

    /**
     * Une facture créée déjà payée (données de démonstration, essais, comme
     * la reprise du 09.11.2026) : le règlement de son reste, à sa date de
     * paiement, avec son moyen, la preuve du terminal et sa clôture. Rien
     * si elle en a déjà un. À appeler une fois ses lignes posées.
     */
    public function recordExistingPayment(Facture $facture): void
    {
        $facture->refresh();
        if ($facture->statut !== StatutFacture::Payee || $facture->moyen_paiement === null
            || $facture->settlements()->where('method', '!=', SettlementMethod::Acompte->value)->exists()) {
            return;
        }
        $acompte = round((float) $facture->settlements()->where('method', SettlementMethod::Acompte->value)->sum('amount'), 2);
        $reste = max(0.0, round($facture->totalTtc() - $acompte, 2));
        if ($reste <= 0) {
            return;
        }
        $preuve = in_array($facture->moyen_paiement, [MoyenPaiement::Tpe, MoyenPaiement::Twint], true)
            ? $facture->paiements()->withoutGlobalScopes()->whereIn('statut', Facture::preuvesDeTerminal())->orderBy('id')->value('id')
            : null;
        $this->create($facture, [
            'method' => SettlementMethod::fromPayment($facture->moyen_paiement),
            'amount' => $reste,
            'received_on' => CarbonImmutable::parse($facture->payee_le ?? $facture->emise_le)->setTimezone($this->zone($facture))->toDateString(),
            'paiement_id' => $preuve,
            'cloture_caisse_id' => $facture->cloture_caisse_id,
            'recorded_by_id' => $facture->encaissee_par_id,
        ]);
    }

    /**
     * Les règlements de toutes les factures qui n'en ont pas (démonstration
     * créée sans hooks de modèle, reprise de données) : l'acompte en ligne,
     * puis le paiement d'une facture déjà payée.
     */
    public function backfillAll(): int
    {
        $count = 0;
        Facture::query()->withoutGlobalScopes()
            ->where(fn ($q) => $q->where('statut', StatutFacture::Payee)->orWhere('acompte', '>', 0))
            ->orderBy('id')
            ->each(function (Facture $facture) use (&$count): void {
                if ((float) $facture->acompte > 0 && ! $facture->settlements()->where('method', SettlementMethod::Acompte->value)->exists()) {
                    $this->recordDeposit($facture);
                }
                $this->recordExistingPayment($facture);
                $count++;
            });

        return $count;
    }

    /**
     * L'acompte payé en ligne, premier règlement de la facture du rendez-vous,
     * daté de son paiement (sinon de l'émission).
     */
    public function recordDeposit(Facture $facture): void
    {
        $demande = $this->demandeOf($facture);
        $paye = $demande->acompte_paye_le ?? $facture->emise_le ?? now();
        $this->create($facture, [
            'method' => SettlementMethod::Acompte,
            'amount' => round((float) $facture->acompte, 2),
            'received_on' => CarbonImmutable::parse($paye)->setTimezone($this->zone($facture))->toDateString(),
        ]);
    }

    /**
     * L'acompte rendu (en tout ou en partie, par l'app, chez Stripe ou au
     * litige) après la création de la facture : une ligne négative de ce qui
     * n'y était pas encore. Rejoué, rien de plus.
     */
    public function syncDeposit(DemandeRendezVous $demande): void
    {
        if ($demande->rendez_vous_id === null) {
            return;
        }
        $facture = Facture::query()->withoutGlobalScopes()->where('rendez_vous_id', $demande->rendez_vous_id)->first();
        if ($facture === null) {
            return;
        }

        DB::transaction(function () use ($facture, $demande): void {
            Facture::query()->withoutGlobalScopes()->whereKey($facture->id)->lockForUpdate()->first();
            $facture->refresh();
            $enregistre = round((float) $facture->settlements()->where('method', SettlementMethod::Acompte->value)->sum('amount'), 2);
            $ecart = round($demande->acompteRetenu() - $enregistre, 2);
            if ($ecart >= -0.005) {
                return;
            }
            $ligne = $this->create($facture, [
                'method' => SettlementMethod::Acompte,
                'amount' => $ecart,
                'received_on' => aujourdhui($this->clinique($facture))->toDateString(),
                'reason' => 'Acompte rendu au propriétaire',
            ]);
            $this->apply($facture, $ligne);
        });
    }

    /**
     * Un paiement de caisse remboursé chez Stripe (en tout ou en partie) ou
     * perdu au litige : une ligne négative de ce qui n'est pas encore noté.
     * Les webhooks donnent le cumul rendu ; rejoué, rien de plus.
     */
    public function syncStripeRefund(Paiement $paiement, int $refundedCents, string $reason, ?CarbonImmutable $refundedAt = null): void
    {
        DB::transaction(function () use ($paiement, $refundedCents, $reason, $refundedAt): void {
            $facture = Facture::query()->withoutGlobalScopes()->whereKey($paiement->facture_id)->lockForUpdate()->first();
            if ($facture === null) {
                return;
            }
            $lignes = Settlement::query()->withoutGlobalScopes()->where('paiement_id', $paiement->id)->lockForUpdate()->get();
            $recu = $lignes->filter(fn (Settlement $s): bool => (float) $s->amount > 0);
            // Un paiement jamais appliqué (rendu tout de suite) n'a rien à reprendre
            if ($recu->isEmpty()) {
                return;
            }
            $dejaRendu = -round((float) $lignes->filter(fn (Settlement $s): bool => (float) $s->amount < 0)->sum('amount'), 2);
            $aRendre = min(round($refundedCents / 100, 2), round((float) $recu->sum('amount'), 2));
            $ecart = round($aRendre - $dejaRendu, 2);
            if ($ecart < 0.005) {
                return;
            }
            $ligne = $this->create($facture, [
                'method' => $recu->first()->method,
                'amount' => -$ecart,
                // Le jour du remboursement chez Stripe, à l'heure de la clinique ; sinon aujourd'hui
                'received_on' => $refundedAt !== null ? $refundedAt->setTimezone($this->zone($facture))->toDateString() : aujourdhui($this->clinique($facture))->toDateString(),
                'paiement_id' => $paiement->id,
                'reason' => $reason,
            ]);
            $this->apply($facture, $ligne);
        });
    }

    /**
     * Le statut suit les règlements : soldée, la facture est payée à la date
     * du règlement qui la solde ; repassée sous son total, elle redevient
     * due. Le moyen affiché est celui des paiements reçus s'il n'y en a qu'un.
     */
    private function apply(Facture $facture, Settlement $ligne): void
    {
        $facture->unsetRelation('settlements');
        $facture->loadMissing('lignes');
        $reste = $facture->resteAPayer();

        if ($facture->statut === StatutFacture::Due && $reste <= 0 && (float) $ligne->amount > 0) {
            $facture->update([
                'statut' => StatutFacture::Payee,
                'payee_le' => $this->instant($facture, CarbonImmutable::parse($ligne->received_on)),
                'moyen_paiement' => $this->moyenUnique($facture),
                'encaissee_par_id' => $ligne->recorded_by_id ?? $facture->encaissee_par_id,
            ]);
        } elseif ($facture->statut === StatutFacture::Payee && $reste > 0) {
            $facture->update(['statut' => StatutFacture::Due, 'payee_le' => null, 'moyen_paiement' => null]);
        }

        // La dette de l'animal suit aussi un paiement partiel (le statut ne change pas)
        PatientDebts::refresh([$facture->patient_id]);
    }

    /** Le moyen des paiements reçus (hors acompte, net des annulations) s'il n'y en a qu'un. */
    private function moyenUnique(Facture $facture): ?string
    {
        $moyens = $facture->settlements()->where('method', '!=', SettlementMethod::Acompte->value)
            ->get()->groupBy(fn (Settlement $s): string => $s->method->value)
            ->filter(fn ($lignes): bool => round((float) $lignes->sum('amount'), 2) > 0)
            ->keys();

        return $moyens->count() === 1 ? (string) $moyens->first() : null;
    }

    /** Reçu aujourd'hui : maintenant ; un autre jour : midi de ce jour à la clinique. */
    private function instant(Facture $facture, CarbonImmutable $jour): CarbonImmutable
    {
        $zone = $this->zone($facture);
        if ($jour->toDateString() === CarbonImmutable::now($zone)->toDateString()) {
            return CarbonImmutable::now();
        }

        return CarbonImmutable::parse($jour->toDateString().' 12:00', $zone)->utc();
    }

    /** @param  array<string, mixed>  $champs */
    private function create(Facture $facture, array $champs): Settlement
    {
        $settlement = new Settlement;
        $settlement->forceFill([
            'organisation_id' => $facture->organisation_id,
            'clinique_id' => $facture->clinique_id,
            'facture_id' => $facture->id,
            ...$champs,
        ])->save();

        return $settlement;
    }

    private function demandeOf(Facture $facture): ?DemandeRendezVous
    {
        return $facture->rendez_vous_id === null ? null
            : DemandeRendezVous::query()->withoutGlobalScopes()->where('rendez_vous_id', $facture->rendez_vous_id)->latest('id')->first();
    }

    private function clinique(Facture $facture): ?Clinique
    {
        return Clinique::query()->withoutGlobalScopes()->find($facture->clinique_id);
    }

    private function zone(Facture $facture): string
    {
        return $this->clinique($facture)?->fuseau_horaire ?: 'Europe/Zurich';
    }
}
