<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Auth\ContexteClinique;
use App\Enums\StatutFacture;
use App\Models\AccountingLock;
use App\Models\Clinique;
use App\Models\Facture;
use Illuminate\Validation\ValidationException;

/**
 * Gardes des gestes de caisse. La facture suit l'organisation (lecture
 * partout), mais lignes, encaissement et clôture sont des gestes de la
 * clinique d'émission — c'est son tiroir et son stock qui bougent.
 */
trait GesteDeCaisse
{
    protected function assurerCliniqueDEmission(Facture $facture): void
    {
        if ($facture->clinique_id !== app(ContexteClinique::class)->cliniqueId()) {
            throw ValidationException::withMessages([
                'facture' => 'Les gestes de caisse se font depuis la clinique d’émission de la facture.',
            ]);
        }
    }

    protected function assurerFactureDue(Facture $facture): void
    {
        if ($facture->statut !== StatutFacture::Due) {
            throw ValidationException::withMessages([
                'facture' => $facture->statut === StatutFacture::Annulee ? 'Cette vente a été annulée.' : 'Cette facture est déjà payée.',
            ]);
        }
    }

    /**
     * Payée en partie (09.11.2026) : ses lignes ne changent plus, le reste
     * se calcule sur ce qui a été encaissé. Annuler d'abord le règlement.
     */
    protected function assurerSansReglement(Facture $facture, string $message = 'Cette facture est déjà payée en partie : annulez d’abord son règlement pour la modifier.'): void
    {
        if ($facture->estPayeeEnPartie()) {
            throw ValidationException::withMessages(['facture' => $message]);
        }
    }

    /** Émise dans un mois remis à la fiduciaire (09.11.2026) : ses lignes ne changent plus. */
    protected function assurerMoisOuvert(Facture $facture): void
    {
        $zone = Clinique::query()->withoutGlobalScopes()->whereKey($facture->clinique_id)->value('fuseau_horaire') ?: 'Europe/Zurich';
        if (AccountingLock::isLocked($facture->clinique_id, $facture->emise_le->setTimezone($zone)->toDateString())) {
            throw ValidationException::withMessages(['facture' => 'Cette facture est d’un mois remis à la fiduciaire : elle ne se modifie plus.']);
        }
    }

    /** Le ticket du comptoir se paie ou s'annule depuis la boutique (28.10.2026). */
    protected function assurerHorsComptoir(Facture $facture, string $message): void
    {
        if ($facture->vente_comptoir) {
            throw ValidationException::withMessages(['facture' => $message]);
        }
    }
}
