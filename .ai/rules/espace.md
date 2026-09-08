---
paths:
  - 'app/Auth/ContexteProprietaire.php, app/Auth/RattachementFichesClient.php, app/Http/Middleware/AppliquerContexteProprietaire.php, app/Http/Controllers/Api/V1/Espace/**, app/Http/Resources/V1/Espace/**, app/Services/*Proprietaire*.php'
---

# Espace propriétaire (/espace)

## Contexte propriétaire, hors contexte clinique
Routes `/espace/*` sous ['auth:sanctum', 'proprietaire'] — JAMAIS `clinique` : aucun ContexteClinique n'est posé, les scopes globaux sont inactifs, et chaque requête filtre EXPLICITEMENT par les fiches du compte (ContexteProprietaire::clientIds() / patientIds()), toutes organisations confondues (un compte peut être client de plusieurs organisations). Les lectures utilisent withoutGlobalScopes() + whereIn explicite ; les écritures posent organisation_id / clinique_id à la main (le hook `creating` ne pose rien sans contexte). Tout jeton convient (un employé peut être propriétaire).

## Rattachement compte ↔ fiches : e-mail VÉRIFIÉ seulement
RattachementFichesClient rattache les fiches `clients` sans compte dont l'e-mail est celui du compte, uniquement si `email_verified_at` est posé (code e-mail). Une inscription par passkey n'a rien prouvé : POST /espace/email/envoyer + /verifier (même CodeEmail que la connexion) prouvent l'adresse puis rattachent. Ne jamais rattacher sur la seule égalité d'e-mail d'un compte non vérifié.

## Dérivés composés côté serveur
L'accueil (AccueilProprietaire) dérive tout : prochain RDV (prevu/salle/cours, toutes cliniques), rappels santé (dernière injection par animal et vaccin, retards + J+30), à faire (devis portail envoyés non signés et non expirés, commandes prêtes, rappels), dernière commande, dernière ordonnance active, clinique d'urgence. Le front n'en recalcule aucun.
