<?php

/*
| Sites web des organisations — domaines personnalisés.
|
| fournisseur : « manuel » (vérification DNS seule : le certificat est
| émis par le serveur, ex. Caddy on-demand TLS) ou « cloudflare »
| (Cloudflare for SaaS : domaine et certificat créés par API, l'app reste
| l'origine, ex. Laravel Cloud).
| cible_cname : nom vers lequel les clients pointent leur sous-domaine.
| ip : adresse à donner pour un domaine nu (sans www), si disponible.
| test_auto : en local, les domaines en .test sont considérés comme
| pointés (pas de DNS réel) — jamais en production.
*/

$front = (string) env('FRONTEND_URL', 'http://localhost:5173');

return [
    'domaines' => [
        'fournisseur' => env('SITES_DOMAINES_FOURNISSEUR', 'manuel'),
        'cible_cname' => env('SITES_CIBLE_CNAME', 'sites.veterinary.ch'),
        'ip' => env('SITES_IP'),
        'test_auto' => (bool) env('SITES_DOMAINES_TEST_AUTO', false),
        // Jamais attribuables à une organisation
        'reserves' => array_values(array_filter([
            parse_url($front, PHP_URL_HOST),
            parse_url((string) env('APP_URL'), PHP_URL_HOST),
            env('SITES_CIBLE_CNAME', 'sites.veterinary.ch'),
        ])),
        'cloudflare' => [
            'jeton' => env('CLOUDFLARE_API_TOKEN'),
            'zone' => env('CLOUDFLARE_ZONE_ID'),
        ],
    ],
];
