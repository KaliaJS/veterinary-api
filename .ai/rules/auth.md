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
Quatorze droits (enum App\Enums\DomainePermission, 23.10.2026) : sept domaines (planning, patients, facturation, stocks, bloc, equipe, organisation) et sept droits fins (rh, prescrire, valider_analyses, signer_comptes_rendus, stupefiants, cloturer_caisse, rembourser). Rôles configurables PAR ORGANISATION (table roles, JSON domaines ; défauts créés via RolesParDefaut — les seeders, qui coupent les événements, l'appellent explicitement ; écran et API organisation/roles) + exceptions par employé (derogations_permission, accorde/retire un droit). Le middleware `clinique` charge employé + rôle + dérogations et met les permissions effectives dans le ContexteClinique (cache le temps de la requête) ; un Gate par droit (AppServiceProvider) et des Policies par modèle les consultent — un changement de permission prend effet immédiatement, sans réémission de jeton. Routes de domaine : `can:<droit>` ; dans les contrôleurs : $this->authorize(...) ou Gate::authorize(<droit>) — PAS authorizeResource. Gestes RH : droit `rh` + garde-fous EmployePolicy/GardeEquipe (jamais son propre rôle, jamais un rôle plus large que le sien, jamais sur un administrateur sans l'être, toujours un administrateur actif). Signataire d'un acte (visite, vaccination, stupéfiant, ordonnance, analyse, compte rendu) = employé du jeton, jamais choisi.

## Passkey / WebAuthn (web-auth/webauthn-lib en direct) et code e-mail
Voie primaire : services portés de foodtruck-api (App\Services\PasskeyAuthService / PasskeyRegisterService / PasskeyService + App\Support\PasskeyJsonSerializer, CeremonieWebauthn) — API sans session : défi en cache à usage unique (defi_id, 5 min, consommé en premier), origines autorisées depuis config/passkey.php (jamais l'en-tête Host), challenge de 32 octets, user handle stable opaque, jamais de catch vide. Secours : code e-mail à 6 chiffres (CodeEmail, haché, usage unique, jamais exposé).
