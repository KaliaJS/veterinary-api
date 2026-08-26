---
paths:
  - 'app/Auth/**'
---

# Auth

## Jetons d'API : clinique liée, abilities par rôle, expiration
Un jeton = un utilisateur + une clinique (personal_access_tokens.clinique_id, modèle JetonApi) + les abilities du rôle de l'employé dans cette clinique (App\Auth\Abilites — contrainte : une ASV n'a pas `facturation` ; les rôles restent à trancher, ne pas figer de liste ailleurs). Expiration via SANCTUM_TOKEN_EXPIRATION (défaut 24 h) ; POST /auth/rafraichir émet un jeton neuf en RECALCULANT les abilities et révoque l'ancien. Auth primaire : passkey/WebAuthn (package laravel/passkeys, routes ignorées, actions invoquées par nos contrôleurs, défi en cache via DefiPasskey) ; secours : code e-mail à 6 chiffres (CodeEmail, jamais exposé). Les requêtes d'authentification (avant contexte) utilisent withoutGlobalScope('clinique').
