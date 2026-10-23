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
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // Webhook des comptes des sociétés (Connect) : un second point d'arrivée, même adresse
        'webhook_secret_comptes' => env('STRIPE_WEBHOOK_SECRET_COMPTES'),
        // Paiements invisibles (23.10.2026) : la plateforme paie les frais du
        // prestataire et les refacture aux sociétés avec sa marge. Tarifs
        // suisses du prestataire par moyen (pour cent, fixe en centimes) :
        // terminal (cartes européennes), TWINT, paiement en ligne (carte
        // suisse, la plus courante ; TWINT en ligne coûte moins).
        'tarifs' => [
            'terminal' => ['taux' => 1.4, 'fixe' => 10],
            'twint' => ['taux' => 1.9, 'fixe' => 30],
            'en_ligne' => ['taux' => 2.9, 'fixe' => 30],
        ],
        // Marge de la plateforme ajoutée au taux (pour cent) : couvre les
        // frais de compte et de virement, laisse environ 0,5 % net
        'marge' => (float) env('STRIPE_MARGE', 0.75),
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
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
