<?php

/*
 * Messages audio et vidéo façon Telegram (demande de Jim, 04.11.2026 ; feu
 * vert du 06.11.2026), dans le fil d'un client. La transcription « →A »
 * suit les règles et le tarif de config/transcription.php.
 */
return [

    'media' => [
        // Durée et taille au plus, par genre (décision de Jim, 07.11.2026) : audio de
        // 2 minutes, vidéo ronde d'une minute. Les poids laissent de la marge sur
        // ce qu'enregistre l'app (audio 32 à 128 kbit/s, vidéo vers 1 Mbit/s)
        'audio' => ['max_seconds' => 120, 'max_kb' => 3 * 1024],
        'video' => ['max_seconds' => 60, 'max_kb' => 20 * 1024],

        // Marge sur la limite : l'app s'arrête une seconde avant ; au moins 1,5 s ici, pour
        // qu'un enregistrement fait dans l'app ne soit jamais refusé (troisième vérification)
        'tolerance_ms' => 1500,
        'min_ms' => 500,

        // Écart permis entre la durée annoncée et celle lue dans le fichier : 2 s, ou 20 %
        'gap_ms' => 2000,
        'gap_ratio' => 0.2,

        // Les marques MP4 d'un enregistrement (Safari, iPhone, Chrome) ; pas d'image HEIC ni AVIF
        'mp4_brands' => ['isom', 'iso2', 'iso3', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42', 'M4A ', 'M4V ', 'dash', 'avc1', 'qt  '],

        // Poids au plus selon la durée lue (deuxième vérification, 07.11.2026) : débit
        // maximal en bit/s, plus une marge en Ko pour les en-têtes
        // L'audio à 64 kbit/s au plus en WebM et Ogg : Chrome et Firefox enregistrent à
        // 32 (troisième vérification) ; un débit plus haut cacherait une durée. L'AAC
        // de Safari (MP4) à 192 kbit/s : Safari peut ignorer le débit demandé (07.11.2026)
        'max_bitrate' => ['audio' => 64000, 'audio_mp4' => 192000, 'video' => 3000000],
        'bitrate_slack_kb' => ['audio' => 16, 'video' => 64],
        // Le MP4 de Safari a une table stsz de 4 octets par trame AAC (48 000 / 1024 par seconde) :
        // sa marge suit la durée (quatrième vérification)
        'slack_bytes_per_second' => ['audio_mp4' => 188, 'video' => 188],

        // Estimation prudente avant l'envoi à Deepgram : le débit le plus bas d'Opus
        'min_audio_bitrate' => 6000,

        // Un propriétaire envoie au plus 10 vidéos par heure et 200 Mo par jour
        'owner_videos_per_hour' => 10,
        'owner_bytes_per_day' => 200 * 1024 * 1024,

        // Les barres de l'onde servies avec le message (de 0 à 1)
        'peaks_max' => 64,

        // Les secondes payées à Deepgram pour les messages d'une organisation, par jour
        // (heure suisse) : 3 h. Un fichier en silence (DTX, 0,4 kbit/s) peut cacher des
        // heures ; ce plafond borne ce qu'il coûte à la plateforme (quatrième vérification)
        'daily_provider_seconds' => 3 * 3600,

        // Une transcription qui échoue se relance, trois appels au plus en tout
        'transcription_attempts' => 3,
    ],

];
