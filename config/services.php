<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Paiements en caisse (docs/data-model.md §6) : TPE via Stripe
    // Terminal (lecteurs « smart », pilotés par le serveur) et TWINT via
    // Stripe. Clés de test en local ; le secret de webhook vient du
    // Dashboard (ou de `stripe listen`).
    // Vetpoint, interface REST des logiciels de cabinet (27.10.2026) : test
    // par défaut ; en production https://www.vetpoint.ch/api/tapsw et
    // https://www.vetpoint.ch/ecommerce/control/profile. La clé est celle de
    // chaque clinique (Réglages), jamais ici
    'vetpoint' => [
        'url' => env('VETPOINT_URL', 'https://test.vetpoint.ch/api/tapsw'),
        'profil' => env('VETPOINT_PROFIL_URL', 'https://test.vetpoint.ch/ecommerce/control/profile'),
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // Webhook des comptes des sociétés (Connect) : un second point d'arrivée, même adresse
        'webhook_secret_comptes' => env('STRIPE_WEBHOOK_SECRET_COMPTES'),
        // Commission de la plateforme sur les paiements au terminal (pour cent du montant)
        'commission_terminal' => (float) env('STRIPE_COMMISSION_TERMINAL', 0.5),
        // Et sur TWINT à la caisse (QR à l'écran), comme le terminal (décision du 23.10.2026)
        'commission_twint' => (float) env('STRIPE_COMMISSION_TWINT', 0.5),
        // Et sur les acomptes en ligne (carte ou TWINT) : des frais sur toutes les transactions
        'commission_en_ligne' => (float) env('STRIPE_COMMISSION_EN_LIGNE', 0.5),
        // Frais de Stripe pour une société suisse, affichés dans Réglages, Paiements. Stripe
        // ne publie pas d'API de ses tarifs : relevés sur stripe.com/ch/pricing, à revoir
        // quand ils changent (taux en %, montants fixes en centimes). Les frais réels de
        // chaque paiement sont lus après coup (fraisIntention, composant des encaissements).
        'frais_stripe' => [
            'releves_le' => '2026-10-23',
            'terminal' => ['taux' => 1.4, 'fixe' => 10, 'taux_autres_cartes' => 2.9],
            'twint' => ['taux' => 1.9, 'fixe' => 30],
            'carte_en_ligne' => ['taux' => 2.9, 'fixe' => 30, 'taux_cartes_etrangeres' => 3.25],
        ],
    ],

    // Achats dans l'app iPhone (StoreKit 2). Les transactions arrivent
    // signées par Apple (JWS) : vérifiées ici contre la racine d'Apple,
    // sans clé ni appel réseau. `accepte_xcode` (en local seulement, sur
    // option) : achats de test lancés depuis Xcode avec le fichier de
    // configuration StoreKit, signés par un certificat local d'Xcode.
    'apple' => [
        'bundle_id' => env('APPLE_BUNDLE_ID', 'ch.veterinary.client'),
        'racines' => [resource_path('apple/AppleRootCA-G3.pem')],
        'accepte_xcode' => env('APP_ENV') === 'local' && (bool) env('APPLE_STOREKIT_XCODE', false),
        // En production, les achats de test (Sandbox) ne valent que pour ces
        // comptes (adresses séparées par des virgules : la revue d'Apple, Jim)
        'comptes_sandbox' => array_values(array_filter(array_map('trim', explode(',', (string) env('APPLE_COMPTES_SANDBOX', ''))))),
    ],

    // Transcription des consultations : fichier entier envoyé après la
    // consultation (pas de temps réel). Modèle médical, langue `multi`
    // (français, avec d'autres langues possibles) ; la clé reste au serveur.
    'deepgram' => [
        'key' => env('DEEPGRAM_API_KEY'),
        'url' => env('DEEPGRAM_URL', 'https://api.deepgram.com'),
        'modele' => env('DEEPGRAM_MODELE', 'nova-3-medical'),
        'langue' => env('DEEPGRAM_LANGUE', 'multi'),
    ],

    // MedReg (OFSP) : vérification du GLN des vétérinaires à l'inscription
    // des cliniques, par les appels publics de son site
    'medreg' => [
        'url' => env('MEDREG_URL', 'https://healthreg-public.admin.ch/medreg/api/public'),
    ],

    // Brouillon du compte rendu SOAP rédigé par Claude à partir de la transcription
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'modele' => env('ANTHROPIC_MODELE', 'claude-opus-5-5'),
        // Budget mensuel de la plateforme (dollars), estimé par appel : au-delà,
        // rédactions et suggestions attendent le mois suivant (23.10.2026).
        // Vide : pas de budget (le plafond de la Console d'Anthropic reste)
        'budget_usd' => is_numeric(env('ANTHROPIC_BUDGET_USD')) ? (float) env('ANTHROPIC_BUDGET_USD') : null,
        'cout_redaction_usd' => (float) env('ANTHROPIC_COUT_REDACTION_USD', 0.15),
        'cout_suggestion_usd' => (float) env('ANTHROPIC_COUT_SUGGESTION_USD', 0.30),
    ],

    // Captcha des formulaires publics (demande de rendez-vous sans compte)
    'turnstile' => [
        'secret' => env('TURNSTILE_SECRET'),
        'url' => env('TURNSTILE_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Téléconsultation (WebRTC, 28.10.2026) : serveurs TURN de Cloudflare,
    // identifiants temporaires créés par l'API ; sans clé, STUN seulement
    'cloudflare_turn' => [
        'key_id' => env('CLOUDFLARE_TURN_KEY_ID'),
        'api_token' => env('CLOUDFLARE_TURN_API_TOKEN'),
        'ttl' => (int) env('CLOUDFLARE_TURN_TTL', 14400),
    ],

];
