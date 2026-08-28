---
paths:
  - 'app/Auth/**, app/Services/Passkey*.php, app/Http/Controllers/Api/V1/Auth/**'
---

# V1 Auth

## Connexion : contrôler le secret, résoudre le rattachement, consommer en dernier
Les voies de connexion (code e-mail, passkey) sont en trois temps, dans cet ordre : CodeEmail::controler / PasskeyAuthService::controler (secret contrôlé, PAS consommé), EmetteurJetons::employePour (rattachement clinique ; `null` = plusieurs cliniques sans choix → réponse 200 ChoixCliniqueResource { choix_clinique, cliniques } sans jeton, secret conservé pour rejouer avec clinique_id), puis consommer() + emettrePour(). Ne jamais consommer avant employePour : un employé multi-clinique brûlerait son code/défi à chaque essai. Trait commun : Auth\Concerns\ConnecteAvecChoixDeClinique. Une assertion passkey invalide brûle quand même le défi. Changement de clinique = POST /auth/clinique (ré-émission, ancien jeton révoqué). Les réponses de session (SessionResource, partagée par /auth/moi et JetonEmisResource) exposent user.id, employe {id, nom, initiales, titre dérivé du nom, role_libelle, role_id}, clinique, cliniques (rattachements), permissions.

WebAuthn est lié au domaine du FRONT : config/passkey.php déduit rp_id et allowed_origins de FRONTEND_URL (défaut http://localhost:5173 → rp `localhost`), jamais d'APP_URL ; PASSKEY_RP_ID / PASSKEY_ALLOWED_ORIGINS ne servent qu'à surcharger. Hors localhost, HTTPS obligatoire (Herd : `herd secure veterinary-web`).
