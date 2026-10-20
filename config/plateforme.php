<?php

/*
 * L'éditeur de la plateforme, émetteur des factures aux cliniques et aux
 * propriétaires (abonnements, packs, objets de la planète, transcription).
 * Meteora Systems Sàrl n'est pas assujettie à la TVA (chiffre d'affaires
 * inférieur à 100 000 CHF, art. 10 LTVA) : aucune TVA n'est facturée.
 */
return [

    'emetteur' => [
        'nom' => env('PLATEFORME_EMETTEUR_NOM', 'Meteora Systems Sàrl'),
        'marque' => 'Veterinary',
        'rue' => env('PLATEFORME_EMETTEUR_RUE', 'Rue du Village 22A'),
        'npa' => env('PLATEFORME_EMETTEUR_NPA', '1055'),
        'ville' => env('PLATEFORME_EMETTEUR_VILLE', 'Froideville'),
        'ide' => env('PLATEFORME_EMETTEUR_IDE', 'CHE-422.618.848'),
        'email' => env('PLATEFORME_EMETTEUR_EMAIL'),
        'assujetti_tva' => (bool) env('PLATEFORME_ASSUJETTI_TVA', false),
    ],

];
