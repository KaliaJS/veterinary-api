<?php

namespace App\Http\Requests\V1\Concerns;

use App\Auth\ContexteClinique;
use App\Models\Clinique;
use App\Models\Societe;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use WeakMap;

/**
 * Règles `exists` bornées au contexte du jeton : `Rule::exists`
 * n'applique pas les global scopes — sans ces clauses, la validation
 * accepterait des identifiants d'une autre clinique. Le champ d'une de
 * ces règles est aussi un entier (24.10.2026) : envoyé en tableau
 * (`client_id[]=3`), `exists` le laisserait passer et le contrôleur
 * recevrait une collection (erreur 500).
 */
trait ReglesScopees
{
    /** @var WeakMap<Exists, true>|null Règles d'identifiant posées par ces aides */
    private ?WeakMap $identifiants = null;

    /** @return array<string, mixed> */
    protected function validationRules(): array
    {
        $regles = parent::validationRules();
        foreach ($regles as $champ => $liste) {
            if (! is_array($liste) || in_array('integer', $liste, true)) {
                continue;
            }
            foreach ($liste as $i => $regle) {
                if ($regle instanceof Exists && isset($this->identifiants[$regle])) {
                    array_splice($liste, $i, 0, ['integer']);
                    $regles[$champ] = $liste;
                    break;
                }
            }
        }

        return $regles;
    }

    private function identifiant(Exists $regle): Exists
    {
        $this->identifiants ??= new WeakMap;
        $this->identifiants[$regle] = true;

        return $regle;
    }

    protected function existeDansLaClinique(string $table): Exists
    {
        return $this->identifiant(Rule::exists($table, 'id')
            ->where('clinique_id', app(ContexteClinique::class)->cliniqueId()));
    }

    protected function existeDansLOrganisation(string $table): Exists
    {
        return $this->identifiant(Rule::exists($table, 'id')
            ->where('organisation_id', app(ContexteClinique::class)->organisationId()));
    }

    /**
     * Un employé affecté à la clinique du jeton (colonne de planning,
     * absence, garde) : les employés n'ont pas de `clinique_id`, la
     * borne passe par leurs affectations.
     */
    protected function employeDeLaClinique(): Exists
    {
        return $this->identifiant(Rule::exists('clinique_employe', 'employe_id')
            ->where('clinique_id', app(ContexteClinique::class)->cliniqueId()));
    }

    /**
     * Un employé de l'organisation, affecté ou non à la clinique du jeton
     * (ex. le signataire d'une visite peut travailler dans une clinique
     * sœur — le dossier médical est partagé).
     */
    protected function employeDeLOrganisation(): Exists
    {
        return $this->existeDansLOrganisation('employes');
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

        return $this->identifiant(Rule::exists('cliniques', 'id')->whereIn('societe_id', $societes));
    }
}
