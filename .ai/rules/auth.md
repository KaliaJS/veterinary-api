---
paths:
  - 'app/Auth/**'
  - 'app/Services/Passkey*.php'
  - 'app/Policies/**'
---

# Auth

## Jetons d'API : clinique liée, expiration — AUCUNE permission dans le jeton
Un jeton = un utilisateur + une clinique (personal_access_tokens.clinique_id, modèle JetonApi), abilities Sanctum à ['*'] : les permissions ne sont JAMAIS figées dans le jeton. Expiration via SANCTUM_TOKEN_EXPIRATION (défaut 24 h) ; POST /auth/rafraichir émet un jeton neuf (même clinique) et révoque l'ancien. Les requêtes d'authentification (avant contexte) utilisent withoutGlobalScope('clinique').

## Permissions : vérifiées en base à chaque requête (Gates + Policies)
Six domaines (enum App\Enums\DomainePermission : planning, patients, facturation, stocks, bloc, equipe). Rôles configurables PAR ORGANISATION (table roles, JSON domaines ; quatre rôles par défaut créés à la création de l'organisation via RolesParDefaut — les seeders, qui coupent les événements, l'appellent explicitement) + dérogations par employé (derogations_permission, accorde/retire un domaine). Le middleware `clinique` charge employé + rôle + dérogations et met les permissions effectives dans le ContexteClinique (cache le temps de la requête) ; un Gate par domaine (AppServiceProvider) et des Policies par modèle (ex. SallePolicy → planning) les consultent — un changement de permission prend effet immédiatement, sans réémission de jeton. Routes de domaine : `can:<domaine>` (ex. can:facturation) ; dans les contrôleurs : $this->authorize(...) — PAS authorizeResource (incompatible avec le contrôleur slim). Contrainte produit : une ASV n'a pas `facturation`.

## Passkey / WebAuthn (web-auth/webauthn-lib en direct) et code e-mail
Voie primaire : services portés de foodtruck-api (App\Services\PasskeyAuthService / PasskeyRegisterService / PasskeyService + App\Support\PasskeyJsonSerializer, CeremonieWebauthn) — API sans session : défi en cache à usage unique (defi_id, 5 min, consommé en premier), origines autorisées depuis config/passkey.php (jamais l'en-tête Host), challenge de 32 octets, user handle stable opaque, jamais de catch vide. Secours : code e-mail à 6 chiffres (CodeEmail, haché, usage unique, jamais exposé).
