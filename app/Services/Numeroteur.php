<?php

namespace App\Services;

use App\Auth\ContexteClinique;
use App\Enums\PieceNumerotee;
use App\Models\Organisation;
use App\Models\SequenceNumerotation;
use Illuminate\Support\Facades\DB;

/**
 * Attribue les numéros de pièces (FC-2026-0345, DV-2026-018…) par
 * séquence annuelle et par organisation (docs/data-model.md §18). La
 * ligne de séquence est verrouillée le temps de l'attribution — deux
 * encaissements simultanés ne partagent jamais un numéro.
 */
class Numeroteur
{
    public function attribuer(PieceNumerotee $piece, ?Organisation $organisation = null): string
    {
        $organisationId = $organisation?->id ?? app(ContexteClinique::class)->organisationId();
        $annee = now()->year;

        return DB::transaction(function () use ($piece, $organisationId, $annee): string {
            $sequence = SequenceNumerotation::query()
                ->where('organisation_id', $organisationId)
                ->where('piece', $piece)
                ->where('annee', $annee)
                ->lockForUpdate()
                ->first();

            $sequence ??= SequenceNumerotation::create([
                'organisation_id' => $organisationId,
                'piece' => $piece,
                'annee' => $annee,
            ]);

            $numero = $piece->formater($annee, $sequence->prochain);

            $sequence->increment('prochain');

            return $numero;
        });
    }
}
