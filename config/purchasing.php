<?php

use App\Services\Purchasing\Connectors\VetpointConnector;

/*
 * Centrales d'achat et commande automatique (décisions de Jim, 03.11.2026).
 * Chaque centrale reliée par son interface se déclare ici par une clé ; le
 * fournisseur d'une clinique la choisit (canal « api »). Rien n'est écrit en
 * dur ailleurs : une nouvelle centrale, c'est un connecteur et une ligne ici.
 */
return [
    'connectors' => [
        'vetpoint' => VetpointConnector::class,
    ],

    // Envoyer seul un panier par une centrale passe une vraie commande : coupé
    // tant que ce n'est pas permis. Coupé, le panier attend et la clinique est
    // prévenue qu'il est prêt (comme un fournisseur « à la main »).
    'auto_send_api' => (bool) env('PURCHASING_AUTO_SEND_API', false),
];
