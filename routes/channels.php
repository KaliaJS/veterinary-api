<?php

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Models\SessionTeleconsultation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

/*
 * Canaux privés du temps réel (Reverb, 28.10.2026). L'autorisation passe
 * par le jeton de la session (POST /api/v1/broadcasting/auth).
 *
 * - clinique.{id} : le personnel de la clinique du jeton (jamais une autre,
 *   même sœur), organisation ouverte ; messages courts sans données
 *   personnelles (état d'un paiement, « les alertes ont changé »…).
 * - clinique.{id}.planning : en plus le droit Planning ; les fiches de
 *   rendez-vous (propriétaire, note de l'accueil) et les files.
 * - organisation.{id}.facturation : le droit Organisation, même fermée
 *   (c'est là qu'on paie l'abonnement) ; les factures de l'abonnement.
 * - utilisateur.{id} : le compte lui-même (propriétaire) ; ses factures Plus.
 * - visio.{id} : la mise en relation d'un appel vidéo (WebRTC) ; le personnel
 *   de la clinique de la session avec le droit Planning, et le client par
 *   le lien de son e-mail (autorisé à part, VisioPubliqueController).
 */
$deLaClinique = function (string $id): bool {
    $contexte = app(ContexteClinique::class);

    return ctype_digit($id) && $contexte->estDefini() && (string) $contexte->cliniqueId() === $id
        && (bool) $contexte->clinique()?->societe?->organisation?->estOuverte();
};

Broadcast::channel('clinique.{id}', fn (User $user, string $id): bool => $deLaClinique($id), ['guards' => ['sanctum']]);

Broadcast::channel('clinique.{id}.planning', fn (User $user, string $id): bool => $deLaClinique($id)
    && Gate::forUser($user)->allows(DomainePermission::Planning->value), ['guards' => ['sanctum']]);

Broadcast::channel('organisation.{id}.facturation', function (User $user, string $id): bool {
    $contexte = app(ContexteClinique::class);

    return ctype_digit($id) && $contexte->estDefini() && (string) $contexte->organisationId() === $id
        && Gate::forUser($user)->allows(DomainePermission::Organisation->value);
}, ['guards' => ['sanctum']]);

Broadcast::channel('utilisateur.{id}', fn (User $user, string $id): bool => ctype_digit($id) && (string) $user->id === $id, ['guards' => ['sanctum']]);

Broadcast::channel('visio.{id}', function (User $user, string $id) use ($deLaClinique): bool {
    $cliniqueId = app(ContexteClinique::class)->cliniqueId();

    return ctype_digit($id) && $cliniqueId !== null && $deLaClinique((string) $cliniqueId)
        && Gate::forUser($user)->allows(DomainePermission::Planning->value)
        && SessionTeleconsultation::withoutGlobalScopes()->whereKey((int) $id)->where('clinique_id', $cliniqueId)->exists();
}, ['guards' => ['sanctum']]);
