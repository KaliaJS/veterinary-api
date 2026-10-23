<?php

/*
 * Suggestion diagnostique (23.10.2026). Claude (modèle de
 * config/services.php, Opus 5.5 par défaut) propose un diagnostic
 * différentiel à partir du compte rendu en cours ; le vétérinaire décide.
 */
return [

    // Prix d'une suggestion rendue, facturé aux cliniques sans TVA
    'tarif_centimes' => (int) env('DIAGNOSTIC_TARIF_CENTIMES', 50),

    // Plafond par défaut d'une organisation et par période, en centimes (modifiable dans ses réglages)
    'plafond_organisation_centimes' => 5000,

    // Réflexion de Claude : un diagnostic différentiel mérite un effort élevé.
    // max_tokens borne la réflexion et la réponse ensemble.
    'effort' => env('DIAGNOSTIC_EFFORT', 'high'),
    'max_tokens' => 16000,

    // Les comptes rendus précédents du patient joints au contexte
    'antecedents' => 5,

];
