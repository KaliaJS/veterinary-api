<?php

namespace App\Http\Requests\V1\Concerns;

use App\Auth\ContexteClinique;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Règles `exists` bornées au contexte du jeton : `Rule::exists`
 * n'applique pas les global scopes — sans ces clauses, la validation
 * accepterait des identifiants d'une autre clinique.
 */
trait ReglesScopees
{
    protected function existeDansLaClinique(string $table): Exists
    {
        return Rule::exists($table, 'id')
            ->where('clinique_id', app(ContexteClinique::class)->cliniqueId());
    }

    protected function existeDansLOrganisation(string $table): Exists
    {
        return Rule::exists($table, 'id')
            ->where('organisation_id', app(ContexteClinique::class)->organisationId());
    }
}
