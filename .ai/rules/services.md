---
paths:
  - 'app/Models/Absence.php,app/Policies/AbsencePolicy.php,app/Services/CalculateurCreneaux.php'
---

# Services

## Absences : scope via employé, mutations droit gerer_absences ; créneaux à parité front
Absence n'a pas de clinique_id : son global scope « clinique » passe par whereHas('employe') — indispensable au route model binding (sinon fuite inter-cliniques). Lecture = domaine planning, mutations (store/update/valider/refuser/destroy) = droit gerer_absences (audit C04, 14.11.2026 : `equipe`, donné à tous, ne suffit plus) ; personne ne décide de sa propre absence sauf un administrateur (sa demande en attente se corrige ou se retire), et la cloche /equipe/conges/a-traiter ne lui montre pas la sienne. Le réglage conges_sans_validation ne vaut que pour /equipe/demandes-conge (soi-même) ; on n'y retire un congé validé que si on l'a posé soi-même (valide_par_user_id = soi), jamais celui qu'un autre a validé. La validation (ou la saisie directe « ok ») annule les RDV de la période via MutationRendezVous (motif « Absence du praticien », sans la raison, un batch par RDV) ; garde ne bloque jamais. CalculateurCreneaux reproduit freeGaps du front : trous ≥ 15 min, tampons et annulés non occupants, pause occupée sur toutes les lignes, aujourd'hui borné à l'heure courante, lignes sous absence bloquante écartées. Les horaires d'ouverture sont des heures LOCALES (cliniques.fuseau_horaire, défaut Europe/Zurich — jamais converties en UTC, sinon décalage au changement d'heure) : le calcul se fait en minutes locales du jour, les créneaux sont rendus en datetimes UTC comme les rendez-vous ; les jours d'absence sont des jours calendaires locaux.
