<?php

namespace App\Services\Notifications;

/** Le passage vers Apple (APNs) : remplaçable dans les essais. */
interface CanalApns
{
    /**
     * Envoie le même contenu à ces jetons d'un environnement ; rend les
     * jetons qu'Apple refuse pour de bon (appareil effacé, app retirée).
     *
     * @param  list<string>  $jetons
     * @param  array<string, mixed>  $contenu
     * @return list<string>
     */
    public function envoyer(array $jetons, string $environnement, string $sujet, array $contenu): array;
}
