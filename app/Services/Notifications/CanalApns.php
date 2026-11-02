<?php

namespace App\Services\Notifications;

/** Le passage vers Apple (APNs) : remplaçable dans les essais. */
interface CanalApns
{
    /**
     * Envoie le même contenu à ces jetons d'un environnement ; rend les
     * jetons qu'Apple refuse pour de bon (appareil effacé, app retirée).
     * `$visible` : une notification affichée (titre, texte, son), sinon un
     * réveil invisible de l'app (montre).
     *
     * @param  list<string>  $jetons
     * @param  array<string, mixed>  $contenu  le corps envoyé à Apple (`aps` compris)
     * @return list<string>
     */
    public function envoyer(array $jetons, string $environnement, string $sujet, array $contenu, bool $visible = false): array;
}
