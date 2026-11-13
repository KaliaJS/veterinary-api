---
paths:
  - 'app/Auth/ContexteProprietaire.php, app/Auth/SousContexteClinique.php, app/Http/Middleware/AppliquerContexteProprietaire.php, app/Http/Controllers/Api/V1/Espace/**, app/Http/Requests/V1/Espace/**, app/Http/Resources/V1/Espace/**, app/Services/*Proprietaire*.php, app/Models/{MessageClientele,PreferencesProprietaire,DemandeRenouvellementOrdonnance}.php'
---

# Espace propriétaire (/espace)

## Contexte propriétaire, hors contexte clinique
Routes `/espace/*` sous ['auth:sanctum', 'proprietaire'] — JAMAIS `clinique` : aucun ContexteClinique n'est posé, les scopes globaux sont inactifs, et chaque requête filtre EXPLICITEMENT par les fiches du compte (ContexteProprietaire::clientIds() / patientIds()), toutes organisations confondues (un compte peut être client de plusieurs organisations). Les lectures utilisent withoutGlobalScopes() + whereIn explicite ; les écritures posent organisation_id / clinique_id à la main (le hook `creating` ne pose rien sans contexte). Tout jeton convient (un employé peut être propriétaire).

## Rattachement compte ↔ fiches : jamais par l'e-mail seul
Aucun rattachement automatique par égalité d'e-mail, même vérifié (l'ancien RattachementFichesClient n'existe plus). Une fiche ne rejoint un espace que par l'invitation de la clinique, prise par son titulaire connecté avec l'adresse invitée et prouvée (voir v1-espace.md, audit C01 du 13.11.2026). POST /espace/email/envoyer + /verifier (même CodeEmail que la connexion) prouvent l'adresse d'un compte inscrit par passkey, sans rattacher aucune fiche.

## Dérivés composés côté serveur
L'accueil (AccueilProprietaire) dérive tout : prochain RDV (prevu/salle/cours, toutes cliniques), rappels santé (dernière injection par animal et vaccin, retards + J+30), à faire (devis portail envoyés non signés et non expirés, commandes prêtes, rappels), dernière commande, dernière ordonnance active, clinique d'urgence. Le front n'en recalcule aucun.

## Agir « comme depuis » une clinique : SousContexteClinique
Créneaux libres, numérotation, création d'une demande de rendez-vous ou d'une commande boutique dépendent du ContexteClinique (horaires, lignes, hook `creating`, Numeroteur) : l'espace les exécute dans SousContexteClinique::pour($clinique, fn) qui pose puis retire le contexte. Jamais de contexte clinique laissé posé après une requête de l'espace.

## Tables propres à l'espace (sans scope global, filtrées par user_id)
`messages_clientele` (fil clinique ↔ compte, auteur proprietaire|clinique, lu_le), `demandes_renouvellement_ordonnance` (traitée par POST /ordonnances/{id}/renouveler côté clinique), `preferences_proprietaire` (défauts du proto, notifications_lues). Côté clinique, les écrans qui lisent messages et demandes de renouvellement restent à faire.
