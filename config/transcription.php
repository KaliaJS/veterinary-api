<?php

/*
 * Transcription des consultations (19.10.2026). Les fournisseurs
 * (Deepgram pour transcrire, Claude pour rédiger) sont réglés dans
 * config/services.php et branchés dans AppServiceProvider.
 */
return [

    // Prix facturé aux cliniques, HT : 2 CHF pour 40 minutes
    'tarif_centimes_minute' => (int) env('TRANSCRIPTION_TARIF_CENTIMES_MINUTE', 5),

    // Plafond mensuel par défaut d'une organisation, en centimes HT (modifiable dans ses réglages)
    'plafond_organisation_centimes' => 20000,

    // Garde-fou de la plateforme : Deepgram n'a pas de plafond de dépense.
    // Budget mensuel en dollars (vide : illimité). Dépense estimée =
    // minutes transcrites × coût d'une minute (tarif de la console
    // Deepgram) ; alerte par e-mail à 80 % puis à 100 %, où plus aucun
    // nouvel enregistrement ne commence ce mois-ci.
    'budget_deepgram_usd' => is_numeric(env('TRANSCRIPTION_BUDGET_DEEPGRAM_USD')) ? (float) env('TRANSCRIPTION_BUDGET_DEEPGRAM_USD') : null,

    'cout_minute_deepgram_usd' => (float) env('TRANSCRIPTION_COUT_MINUTE_DEEPGRAM_USD', 0.0065),

    'alerte_email' => env('TRANSCRIPTION_ALERTE_EMAIL'),

    // Règles d'enregistrement appliquées par l'app (secondes)
    'enregistrement' => [
        'duree_max' => 3600,
        'prolongation' => 1800,
        'silence_alerte' => 300,
        'silence_arret' => 600,
    ],

    // Envoi par morceaux : chacun passe sous les limites d'upload des serveurs
    'morceau_max_ko' => 1024,
    'morceaux_max' => 200,

    // Bornes d'un enregistrement (23.10.2026) : parties, octets reçus en tout,
    // rédactions du compte rendu (relances comprises)
    'parties_max' => 12,
    'octets_max_enregistrement' => 300 * 1024 * 1024,
    'redactions_max' => 3,

    // Audio d'une partie non transcrite (erreur) effacé au bout de ce délai
    'conservation_audio_jours' => 7,
    // Audio d'une partie en erreur : gardé le temps de relancer, pas plus (23.10.2026)
    'conservation_erreur_jours' => 2,

    // Formats d'audio acceptés (enregistreur de l'iPhone, navigateurs)
    'formats' => ['audio/mp4', 'audio/m4a', 'audio/x-m4a', 'audio/aac', 'audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/wav'],

];
