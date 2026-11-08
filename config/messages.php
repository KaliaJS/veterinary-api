<?php

/*
 * Messages audio et vidéo façon Telegram (demande de Jim, 04.11.2026 ; feu
 * vert du 06.11.2026), dans le fil d'un client. La transcription « →A »
 * suit les règles et le tarif de config/transcription.php.
 */
return [

    'media' => [
        // Durée et taille au plus, par genre (décision de Jim, 07.11.2026) : audio de
        // 2 minutes ; vidéo ronde de 2 minutes pile depuis le 08.11.2026 (une minute avant).
        // Les poids laissent de la marge sur ce qu'enregistre l'app (audio 32 à 128 kbit/s,
        // vidéo vers 1 Mbit/s)
        'audio' => ['max_seconds' => 120, 'max_kb' => 3 * 1024],
        'video' => ['max_seconds' => 120, 'max_kb' => 40 * 1024],

        // Marge sur la limite : l'app s'arrête pile à la limite (08.11.2026) ; un onglet caché
        // peut retarder son minuteur d'une seconde, et l'arrêt du MediaRecorder prend un peu de
        // temps : 2,5 s ici, pour qu'un enregistrement fait dans l'app ne soit jamais refusé
        'tolerance_ms' => 2500,
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

    /*
     * Les pièces jointes (décisions de Jim, 08.11.2026) : photos et PDF, dix
     * fichiers par message, 20 Mo chacun. Une photo est réduite à 2000 px de
     * côté au plus (le navigateur le fait déjà, le serveur s'en assure) et
     * réencodée sans ses métadonnées ; son aperçu fait 480 px.
     */
    'attachments' => [
        'max_files' => 10,
        'max_kb' => 20 * 1024,
        // 60 Mo au plus par message (décision de Jim, 08.11.2026) : ce que le serveur accepte en une fois
        'max_total_kb' => 60 * 1024,
        'image_max_side' => 2000,
        'thumb_side' => 480,
        'jpeg_quality' => 85,
        // Une image de plus de 60 mégapixels n'est pas décodée (une bombe de décompression)
        'max_pixels' => 60_000_000,
        // Un propriétaire envoie au plus 60 fichiers par heure ; les 200 Mo du jour comptent ensemble l'audio, la vidéo et les pièces jointes
        'owner_files_per_hour' => 60,
    ],

];
