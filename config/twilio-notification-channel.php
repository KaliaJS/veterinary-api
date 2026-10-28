<?php

/*
 * SMS par Twilio (décision de Jim, 28.10.2026), configuration du paquet
 * laravel-notification-channels/twilio : une clé d'API (SK…, révocable)
 * plutôt que le jeton maître du compte ; expéditeur « Veterinary » (sans
 * numéro, permis en Suisse sans enregistrement) ; la clé est en région
 * Irlande, les appels passent par Dublin.
 */
return [
    'enabled' => (bool) env('TWILIO_ENABLED', true),
    'username' => env('TWILIO_API_KEY'),
    'password' => env('TWILIO_API_SECRET'),
    'auth_token' => null,
    'account_sid' => env('TWILIO_ACCOUNT_SID'),

    // Jamais de numéro : le nom de l'expéditeur (11 caractères au plus)
    'from' => env('TWILIO_EXPEDITEUR', 'Veterinary'),
    'alphanumeric_sender' => env('TWILIO_EXPEDITEUR', 'Veterinary'),
    'shorten_urls' => false,
    'sms_service_sid' => null,
    'debug_to' => env('TWILIO_DEBUG_TO'),

    // Région de la clé (lue par AppServiceProvider, hors du paquet)
    'region' => env('TWILIO_REGION', 'ie1'),
    'edge' => env('TWILIO_EDGE', 'dublin'),

    // Aucune erreur tue : l'appelant sait qu'un SMS n'est pas parti
    'ignored_error_codes' => [],
];
