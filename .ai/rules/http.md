---
paths:
  - 'routes/api.php,app/Http/**'
---

# Http

## Conventions transversales de l'API v1
API JSON uniquement, préfixe /api/v1, jetons Sanctum purs — jamais de session SPA ni EnsureFrontendRequestsAreStateful. Toute route authentifiée passe par ['auth:sanctum', 'clinique'] : le middleware clinique pose le ContexteClinique du jeton et les global scopes font l'isolation — AUCUN filtre clinique dans les contrôleurs. Entrées via FormRequest (app/Http/Requests/V1), sorties via JsonResource (app/Http/Resources/V1, enveloppe `data`), pagination `per_page` (défaut 25, max 100) via Controller::parPage(), erreurs au format Laravel { message, errors? } forcé JSON (ForcerReponseJson). Domaines protégés par Gates vérifiés en base à chaque requête (`can:facturation` sur les groupes de routes, Policies + $this->authorize() dans les contrôleurs) — cf. .ai/rules/auth.md.
