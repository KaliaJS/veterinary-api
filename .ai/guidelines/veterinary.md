# Veterinary — règles métier

API JSON uniquement. Le front est dans `../veterinary-web`
(Vue 3 + Pinia), complet sur des données seedées, en lecture
seule depuis ce dépôt : ne jamais le modifier.

`docs/data-model.md` fait autorité sur le modèle de données. Il a
été extrait du prototype puis relu après le portage complet du
front. Toute divergence entre ce document et une implémentation
doit être signalée, pas absorbée.

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