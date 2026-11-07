<?php

namespace App\Models\Concerns;

use App\Auth\ContexteClinique;

/**
 * L'employé qui a fait l'acte (pesée, document), gardé à sa création
 * (06.11.2026) : la personne connectée dans la clinique en cours. Sans
 * clinique (le propriétaire dans son espace, un lien signé à distance),
 * aucun. Une valeur posée à la création l'emporte.
 */
trait RecordsAuthor
{
    public static function bootRecordsAuthor(): void
    {
        static::creating(function (self $modele): void {
            if (! array_key_exists('employe_id', $modele->getAttributes())) {
                $modele->employe_id = app(ContexteClinique::class)->employe()?->id;
            }
        });
    }
}
