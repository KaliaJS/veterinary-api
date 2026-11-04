<?php

/*
 * Taux suisses de la TVA, en %, en vigueur depuis le 1er janvier 2024
 * (AFC). Le seul endroit où ils s'écrivent : factures, devis, ventes et
 * articles les lisent ici (décision de Jim, 03.11.2026). Le taux réduit
 * va aux soins des animaux de rente et à la vente des médicaments,
 * antiparasitaires, produits de phytothérapie et aliments ; le taux
 * normal au reste (Info TVA 21 de l'AFC).
 */
return [
    'rates' => [
        'normal' => 8.1,
        'reduced' => 2.6,
    ],
];
