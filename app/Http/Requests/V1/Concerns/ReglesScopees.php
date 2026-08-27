<?php

namespace App\Http\Requests\V1\Concerns;

use App\Auth\ContexteClinique;
use App\Models\Clinique;
use App\Models\Societe;
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

    /**
     * Variante pour les tables portant `clinique_id` mais référencées
     * au niveau organisation (ex. le signataire d'une visite peut être
     * l'employé d'une clinique sœur — le dossier médical est partagé).
     */
    protected function existeDansLesCliniquesDeLOrganisation(string $table): Exists
    {
        $cliniques = Clinique::query()
            ->whereRelation('societe', 'organisation_id', app(ContexteClinique::class)->organisationId())
            ->pluck('id')
            ->all();

        return Rule::exists($table, 'id')->whereIn('clinique_id', $cliniques);
    }

    /**
     * Une clinique de l'organisation du jeton (la table `cliniques` n'a
     * pas de `clinique_id` : la borne passe par la société) — cible des
     * gestes RH et de l'écran Organisation.
     */
    protected function estUneCliniqueDeLOrganisation(): Exists
    {
        $societes = Societe::query()
            ->where('organisation_id', app(ContexteClinique::class)->organisationId())
            ->pluck('id')
            ->all();

        return Rule::exists('cliniques', 'id')->whereIn('societe_id', $societes);
    }
}
