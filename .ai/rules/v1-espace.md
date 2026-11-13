---
paths:
  - 'app/Services/Clients/**,app/Http/Controllers/Api/V1/{Client,CompteClient}*.php,app/Http/Controllers/Api/V1/Espace/**'
---

# V1 Espace

## La clinique n'ouvre jamais de compte : une fiche rejoint un espace par invitation acceptée
Décision de Jim, 13.11.2026 (audit C01) : POST clients/{client}/compte (« ouvrir un compte » sur une adresse tapée, décidé le 24.10.2026) est supprimé, avec OwnerAccount::open. Il rattachait un compte existant sans l'accord de son titulaire et recopiait ses coordonnées dans la fiche, pour n'importe quelle organisation. Le seul geste de la clinique est l'invitation (ClientSpaceInvitationController) ; OwnerAccount::attach n'est appelé que quand le titulaire la prend, connecté avec l'adresse invitée et prouvée. Ne jamais renvoyer à une clinique les coordonnées d'un compte avant cette acceptation. Le retrait (DELETE clients/{client}/compte) reste.
