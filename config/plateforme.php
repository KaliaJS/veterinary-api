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

    // Vérification à la main des cliniques inscrites (GLN introuvable dans
    // MedReg, autre nom, MedReg en panne) : e-mail avec Valider et Refuser
    'verification_email' => env('PLATEFORME_VERIFICATION_EMAIL') ?: env('TRANSCRIPTION_ALERTE_EMAIL') ?: env('PLATEFORME_EMETTEUR_EMAIL'),

    // Alertes techniques de la plateforme (jeton Cloudflare refusé, 26.10.2026)
    'alerte_email' => env('PLATEFORME_ALERTE_EMAIL') ?: env('PLATEFORME_VERIFICATION_EMAIL') ?: env('TRANSCRIPTION_ALERTE_EMAIL') ?: env('PLATEFORME_EMETTEUR_EMAIL'),

    // L'annuaire public et ses créneaux, gardés quelques secondes : chaque
    // visite ne recalcule pas toutes les cliniques (23.10.2026). 0 : jamais
    'annuaire_cache_secondes' => (int) env('ANNUAIRE_CACHE_SECONDES', 60),

];
