<?php

namespace App\Auth;

use App\Models\Clinique;
use Closure;

/**
 * Exécute un calcul « comme depuis » une clinique donnée : pose le
 * ContexteClinique (scopes globaux, horaires, lignes de planning) le
 * temps de la fermeture, puis rétablit celui d'avant. Sert à l'espace propriétaire,
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
        // Le contexte d'avant revient ensuite (23.10.2026) : appelé depuis
        // une requête de clinique (webhook, acompte), il ne la laisse pas
        // sans contexte, ni sur une autre clinique
        $avant = $this->contexte->etat();
        $this->contexte->definir($clinique);

        try {
            return $calcul();
        } finally {
            $this->contexte->retablir($avant);
        }
    }
}
