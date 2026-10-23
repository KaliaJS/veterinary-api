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
        $contexte = app(ContexteClinique::class);
        $organisationId = $organisation?->id ?? $contexte->organisationId();
        // L'année de la clinique : la nuit du Nouvel An, pas celle de l'UTC
        $annee = now($contexte->clinique()?->fuseau_horaire ?: 'Europe/Zurich')->year;

        return DB::transaction(function () use ($piece, $organisationId, $annee): string {
            // La première pièce de l'année crée sa ligne sans course : deux
            // premières en même temps trouvent la même, puis l'attendent
            SequenceNumerotation::query()->insertOrIgnore([
                'organisation_id' => $organisationId,
                'piece' => $piece->value,
                'annee' => $annee,
                'prochain' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = SequenceNumerotation::query()
                ->where('organisation_id', $organisationId)
                ->where('piece', $piece)
                ->where('annee', $annee)
                ->lockForUpdate()
                ->firstOrFail();

            $numero = $piece->formater($annee, $sequence->prochain);

            $sequence->increment('prochain');

            return $numero;
        });
    }
}
