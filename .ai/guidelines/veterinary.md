# Veterinary — règles métier

API JSON uniquement. Le front est dans `../veterinary-web`
(Vue 3 + Pinia). Les deux dépôts se travaillent ensemble : le
plan de bascule est dans `docs/branchement.md`, et chaque dépôt
a son `docs/todo.md`.

Commits séparés par dépôt — jamais un commit qui mélange les deux.
Lire le `CLAUDE.md` de `../veterinary-web` avant d'y écrire : il a
ses propres conventions.

`docs/data-model.md` fait autorité sur le modèle de données. Toute
divergence entre ce document et une implémentation doit être
signalée, pas absorbée.

## Règles

- Multi-clinique : presque toutes les tables portent une clinique.
- Horaires, pauses et jours fermés sont des réglages de clinique,
  jamais des constantes.
- Les rendez-vous peuvent se chevaucher : aucune contrainte
  d'exclusivité. Prévoir les index composés en conséquence
  (clinic_id, lane_id, starts_at).
- Aucune suppression de rendez-vous : statut `annule` avec auteur,
  horodatage et motif.
- Les mutations de rendez-vous sont auditées : qui, quand, ancienne
  et nouvelle valeur, par champ.
- Datetimes stockés en UTC.

## Méthode

Travailler par domaine complet, pas par tranche. Décider et avancer
plutôt que demander validation. Signaler les divergences et les
arbitrages dans le rapport final.

Commiter en fin de domaine, en français. Jamais de push.