---
paths:
  - 'app/Models/**'
---

# Models

## Règles métier structurantes du modèle (docs/data-model.md fait autorité)
Jamais de suppression de rendez-vous : statut 'annule' + audit (rendez_vous_audits, une ligne par champ, batch_id ULID groupe un geste, immuable — UPDATED_AT = null). Chevauchement de RDV autorisé : aucune contrainte d'exclusivité, index composé (clinique_id, ligne_planning_id, starts_at). Horaires/pauses/jours fermés = horaires_ouverture par clinique, jamais des constantes. Datetimes en UTC. Le compteur no-show et l'alerte « solde » sont dérivés, pas stockés. Toute divergence avec docs/data-model.md se signale, ne s'absorbe pas.
