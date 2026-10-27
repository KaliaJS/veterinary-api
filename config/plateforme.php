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

    // Formulaire de contact du site (27.10.2026) : l'adresse qui reçoit les
    // messages, jamais montrée sur le site ; sinon celle des alertes
    'contact_email' => env('PLATEFORME_CONTACT_EMAIL') ?: env('PLATEFORME_ALERTE_EMAIL') ?: env('PLATEFORME_VERIFICATION_EMAIL') ?: env('TRANSCRIPTION_ALERTE_EMAIL') ?: env('PLATEFORME_EMETTEUR_EMAIL'),

    // L'annuaire public et ses créneaux, gardés quelques secondes : chaque
    // visite ne recalcule pas toutes les cliniques (23.10.2026). 0 : jamais
    'annuaire_cache_secondes' => (int) env('ANNUAIRE_CACHE_SECONDES', 60),

    // Accès sur invitation (décision de Jim, 27.10.2026) : tant que c'est
    // vrai, seules les adresses invitées créent un compte (liste de la
    // plateforme, équipe ou fiche client d'une clinique vérifiée). Fermé
    // par défaut : un oubli au déploiement n'ouvre rien. À false le jour
    // de l'ouverture
    'acces_sur_invitation' => (bool) env('ACCES_SUR_INVITATION', true),

    // Compte de revue d'Apple (TestFlight externe, 27.10.2026) : l'adresse
    // se connecte avec ce code fixe de 8 chiffres (secret), sans e-mail, même
    // pendant l'accès sur invitation. Vide ou mal formé : rien ne change
    'revue_apple' => [
        'email' => env('REVUE_APPLE_EMAIL'),
        'code' => env('REVUE_APPLE_CODE'),
    ],

    // Fonctions en essai (Laravel Pennant, 27.10.2026) : nom => libellé
    // montré dans l'écran Plateforme. Une fonction ajoutée ici s'active
    // ensuite pour un compte, une organisation ou tout le monde ; ses
    // routes prennent le middleware `fonction:nom`, l'app la teste par
    // `auth.aFonction('nom')`. Retirée d'ici le jour où elle sort d'essai
    'fonctions_essai' => [],

];
