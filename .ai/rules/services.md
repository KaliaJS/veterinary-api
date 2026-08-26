---
paths:
  - 'app/Models/Absence.php,app/Policies/AbsencePolicy.php,app/Services/CalculateurCreneaux.php'
---

# Services

## Absences : scope via employé, mutations domaine equipe ; créneaux à parité front
Absence n'a pas de clinique_id : son global scope « clinique » passe par whereHas('employe') — indispensable au route model binding (sinon fuite inter-cliniques). Lecture = domaine planning, mutations (store/update/valider/refuser/destroy) = domaine equipe. La validation (ou la saisie directe « ok ») annule les RDV de la période via MutationRendezVous (motif « Absence praticien — … », un batch par RDV) ; garde ne bloque jamais. CalculateurCreneaux reproduit freeGaps du front : trous ≥ 15 min, tampons et annulés non occupants, pause occupée sur toutes les lignes, aujourd'hui borné à l'heure courante, lignes sous absence bloquante écartées. Les horaires d'ouverture sont des heures LOCALES (cliniques.fuseau_horaire, défaut Europe/Zurich — jamais converties en UTC, sinon décalage au changement d'heure) : le calcul se fait en minutes locales du jour, les créneaux sont rendus en datetimes UTC comme les rendez-vous ; les jours d'absence sont des jours calendaires locaux.
