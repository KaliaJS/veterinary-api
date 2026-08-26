<?php

namespace App\Models\Concerns;

use App\Auth\ContexteClinique;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Variante du scope multi-clinique pour les modèles de niveau
 * organisation (patients, clients — docs/data-model.md §2) : le
 * contexte clinique du jeton résout l'organisation, partagée par
 * toutes les cliniques de celle-ci.
 */
trait AppartientAUneOrganisation
{
    protected static function bootAppartientAUneOrganisation(): void
    {
        static::addGlobalScope('organisation', function (Builder $builder): void {
            $contexte = app(ContexteClinique::class);

            if ($contexte->estDefini()) {
                $builder->where($builder->qualifyColumn('organisation_id'), $contexte->organisationId());
            }
        });

        static::creating(function (Model $model): void {
            $contexte = app(ContexteClinique::class);

            if (! $contexte->estDefini()) {
                return;
            }

            if ($model->getAttribute('organisation_id') === null) {
                $model->setAttribute('organisation_id', $contexte->organisationId());
            } elseif ((int) $model->getAttribute('organisation_id') !== $contexte->organisationId()) {
                throw new AuthorizationException('Écriture hors de l’organisation du jeton.');
            }
        });
    }
}
