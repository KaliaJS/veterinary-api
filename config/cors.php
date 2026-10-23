<?php

/*
 * CORS (23.10.2026) : seules les origines de l'app sont autorisées
 * (CORS_ALLOWED_ORIGINS, séparées par des virgules, l'app iPhone
 * comprise : capacitor://localhost). Les domaines ACTIFS des sites des
 * cliniques s'y ajoutent requête par requête (AutoriserDomainesSites).
 * Jetons porteurs, jamais de cookie : pas de credentials.
 */
return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 600,

    'supports_credentials' => false,

];
