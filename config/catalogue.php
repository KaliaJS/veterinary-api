<?php

// Catalogue des médicaments vétérinaires (16.10.2026) : flux Open Government
// Data de Swissmedic (mensuel, sans clé ; l ETag du dernier fichier est
// renvoyé, le serveur répond « inchangé » sans rien envoyer) et base
// d'articles Refdata (GTIN, quotidienne, clé de souscription du portail
// développeur en en-tête X-API-Key ; toujours téléchargée, comparée par
// empreinte). Aucune archive n est conservée sur le disque. Les imports
// planifiés ne tournent qu'en production ; en local, les commandes
// `catalogue:swissmedic` et `catalogue:refdata` à la main.
return [
    'swissmedic' => [
        'url' => env('CATALOGUE_SWISSMEDIC_URL', 'https://ogd.swissmedic.cloud/ogd-arzneimittel/Daten/OGD.zip'),
        'taille_max' => 20 * 1024 * 1024,
        'taille_max_entree' => 250 * 1024 * 1024,
    ],
    'refdata' => [
        'url' => env('CATALOGUE_REFDATA_URL', 'https://api.refdata.ch/articles/2.0/Refdata.Articles.zip'),
        'cle' => env('REFDATA_API_KEY'),
        'taille_max' => 100 * 1024 * 1024,
        'taille_max_entree' => 250 * 1024 * 1024,
    ],
    // Destinataire des nouveautés (nouveaux vaccins à compléter) ; vide : pas d e-mail
    'notification_email' => env('CATALOGUE_NOTIFICATION_EMAIL'),
];
