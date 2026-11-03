<?php

namespace App\Models\Concerns;

use App\Auth\ContexteClinique;

/**
 * L'organisation qui a fait l'acte (pesée, document, visite, vaccin,
 * relevé), gardée à sa création (02.11.2026, étape 0 de la fusion des
 * dossiers) : celle de la clinique en cours. Sans clinique (le propriétaire
 * dans son espace), aucune. Une valeur posée à la création l'emporte.
 */
trait RecordsSourceOrganisation
{
    public static function bootRecordsSourceOrganisation(): void
    {
        static::creating(function (self $modele): void {
            if (! array_key_exists('source_organisation_id', $modele->getAttributes())) {
                $modele->source_organisation_id = app(ContexteClinique::class)->organisationId();
            }
        });
    }
}
