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
    // Au-delà, plus aucun nouvel enregistrement ce mois-ci ; alerte par
    // e-mail à 80 % puis à 100 %.
    'plafond_global_minutes' => (int) env('TRANSCRIPTION_PLAFOND_GLOBAL_MINUTES', 20000),

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

    // Audio d'une partie non transcrite (erreur) effacé au bout de ce délai
    'conservation_audio_jours' => 7,

    // Formats d'audio acceptés (enregistreur de l'iPhone, navigateurs)
    'formats' => ['audio/mp4', 'audio/m4a', 'audio/x-m4a', 'audio/aac', 'audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/wav'],

];
