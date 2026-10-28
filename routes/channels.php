<?php

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

/*
 * Canaux privés du temps réel (Reverb, 28.10.2026). L'autorisation passe
 * par le jeton de la session (POST /api/v1/broadcasting/auth), dans la
 * clinique du jeton, jamais une autre (même sœur).
 *
 * - clinique.{id} : tout le personnel de la clinique ; messages courts sans
 *   données personnelles (état d'un paiement, « les alertes ont changé »).
 * - clinique.{id}.planning : avec le droit Planning ; les fiches de
 *   rendez-vous (propriétaire, note de l'accueil) et les files.
 */
$deLaClinique = function (string $id): bool {
    $contexte = app(ContexteClinique::class);

    return ctype_digit($id) && $contexte->estDefini() && (string) $contexte->cliniqueId() === $id;
};

Broadcast::channel('clinique.{id}', fn (User $user, string $id): bool => $deLaClinique($id), ['guards' => ['sanctum']]);

Broadcast::channel('clinique.{id}.planning', fn (User $user, string $id): bool => $deLaClinique($id)
    && Gate::forUser($user)->allows(DomainePermission::Planning->value), ['guards' => ['sanctum']]);
