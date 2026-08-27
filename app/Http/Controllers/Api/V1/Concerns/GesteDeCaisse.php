<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Auth\ContexteClinique;
use App\Enums\StatutFacture;
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
                'facture' => 'Cette facture est déjà payée.',
            ]);
        }
    }
}
