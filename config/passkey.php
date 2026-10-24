<?php

/*
 * WebAuthn est lié au domaine du FRONT (la page qui appelle
 * navigator.credentials), jamais à celui de l'API : le RP ID et les
 * origines autorisées se déduisent de FRONTEND_URL, pas d'APP_URL.
 * Voir .env.example pour les valeurs de développement.
 */
$front = (string) env('FRONTEND_URL', 'http://localhost:5173');

return [

    /*
    |--------------------------------------------------------------------------
    | Relying Party WebAuthn
    |--------------------------------------------------------------------------
    |
    | L'identifiant du RP est le domaine du front (sans schéma ni port) :
    | les passkeys ne fonctionnent que sur ce domaine et ses sous-domaines,
    | et le navigateur refuse l'opération si la page ne vient pas de ce
    | domaine (« domaine RP invalide »). Les origines autorisées (schéma +
    | domaine + port, séparées par des virgules) sont vérifiées côté
    | serveur — jamais déduites de l'en-tête Host de la requête. Hors
    | `localhost`, WebAuthn exige un contexte sécurisé (HTTPS).
    |
    */

    'rp_id' => env('PASSKEY_RP_ID', parse_url($front, PHP_URL_HOST) ?: 'localhost'),

    'rp_name' => env('PASSKEY_RP_NAME', env('APP_NAME', 'Veterinary')),

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PASSKEY_ALLOWED_ORIGINS', $front)),
    ))),

    /*
    |--------------------------------------------------------------------------
    | App iPhone
    |--------------------------------------------------------------------------
    |
    | La vue web de l'app (capacitor://localhost) ne peut pas faire de
    | cérémonie WebAuthn : l'app passe par iOS, pour le domaine public du
    | site déclaré dans ses domaines associés (`webcredentials:`) et
    | confirmé par le site (/.well-known/apple-app-site-association). iOS
    | signe alors l'origine https://<domaine>. En production, c'est le même
    | domaine que le front : une passkey sert dans Safari et dans l'app.
    |
    */

    'app_rp_id' => env('PASSKEY_APP_RP_ID', 'veterinary.app'),

    /*
    |--------------------------------------------------------------------------
    | Défis
    |--------------------------------------------------------------------------
    */

    'defi_validite_minutes' => 5,

];
