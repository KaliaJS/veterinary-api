<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Relying Party WebAuthn
    |--------------------------------------------------------------------------
    |
    | L'identifiant du RP est le domaine (sans schéma) : les passkeys ne
    | fonctionnent que sur ce domaine et ses sous-domaines. Les origines
    | autorisées sont vérifiées côté serveur — jamais déduites de l'en-tête
    | Host de la requête.
    |
    */

    'rp_id' => env('PASSKEY_RP_ID', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),

    'rp_name' => env('PASSKEY_RP_NAME', env('APP_NAME', 'Veterinary')),

    'allowed_origins' => array_filter(explode(',', (string) env('PASSKEY_ALLOWED_ORIGINS', env('APP_URL', 'http://localhost')))),

    /*
    |--------------------------------------------------------------------------
    | Défis
    |--------------------------------------------------------------------------
    */

    'defi_validite_minutes' => 5,

];
