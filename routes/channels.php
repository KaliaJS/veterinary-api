<?php

use App\Auth\ContexteClinique;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Canaux privés du temps réel (Reverb, 28.10.2026). L'autorisation passe
 * par le jeton de la session (POST /api/v1/broadcasting/auth) : le canal
 * d'une clinique s'ouvre à qui travaille dans cette clinique, celle du
 * jeton, jamais une autre (même sœur).
 */
Broadcast::channel('clinique.{id}', function (User $user, int $id): bool {
    $contexte = app(ContexteClinique::class);

    return $contexte->estDefini() && $contexte->cliniqueId() === $id;
}, ['guards' => ['sanctum']]);
