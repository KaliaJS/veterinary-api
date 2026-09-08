<?php

namespace App\Auth;

use App\Models\Clinique;
use Closure;

/**
 * Exécute un calcul « comme depuis » une clinique donnée : pose le
 * ContexteClinique (scopes globaux, horaires, lignes de planning) le
 * temps de la fermeture, puis le retire. Sert à l'espace propriétaire,
 * qui n'a pas de clinique de jeton mais s'adresse à une clinique
 * précise (créneaux libres, demande de rendez-vous).
 */
class SousContexteClinique
{
    public function __construct(private ContexteClinique $contexte) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $calcul
     * @return T
     */
    public function pour(Clinique $clinique, Closure $calcul): mixed
    {
        $this->contexte->definir($clinique);

        try {
            return $calcul();
        } finally {
            $this->contexte->oublier();
        }
    }
}
