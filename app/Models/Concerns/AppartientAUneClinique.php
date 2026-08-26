<?php

namespace App\Models\Concerns;

use App\Auth\ContexteClinique;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Scope multi-clinique global pour les modèles portant `clinique_id`.
 * Actif uniquement quand un contexte clinique est défini (requêtes API
 * authentifiées) — les seeders et commandes console restent unscopés.
 * En création, la clinique du contexte est imposée ; écrire pour une
 * autre clinique est refusé.
 */
trait AppartientAUneClinique
{
    protected static function bootAppartientAUneClinique(): void
    {
        static::addGlobalScope('clinique', function (Builder $builder): void {
            $contexte = app(ContexteClinique::class);

            if ($contexte->estDefini()) {
                $builder->where($builder->qualifyColumn('clinique_id'), $contexte->cliniqueId());
            }
        });

        static::creating(function (Model $model): void {
            $contexte = app(ContexteClinique::class);

            if (! $contexte->estDefini()) {
                return;
            }

            if ($model->getAttribute('clinique_id') === null) {
                $model->setAttribute('clinique_id', $contexte->cliniqueId());
            } elseif ((int) $model->getAttribute('clinique_id') !== $contexte->cliniqueId()) {
                throw new AuthorizationException('Écriture hors de la clinique du jeton.');
            }
        });
    }
}
