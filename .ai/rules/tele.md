---
paths:
  - 'app/Models/SessionTeleconsultation.php,app/Services/ProjectionPlanningTele.php,app/Http/Controllers/Api/V1/{SessionTeleconsultation,FinTeleconsultation}*.php,app/Http/Requests/V1/Tele/**'
---

# Tele

## Téléconsultation : la session est maîtresse de l'appel, durée servie par le serveur
Sessions = CLINIQUE, domaine `planning` (la session pilote les statuts du RDV). Deux entrées : RDV `tele` non annulé/noshow de la clinique (patient et motif DÉRIVÉS du RDV, une seule session en cours par RDV — mais PAS d'unicité en base : quitter puis rappeler crée une nouvelle session sur le même RDV) ou appel libre vers un patient de l'organisation. Sync à sens unique via ProjectionPlanningTele → MutationRendezVous (auditée) : démarrage → `cours` (depuis prevu/salle seulement), « Terminer » (cloturer=true) → `fini` + facture de l'acte `tele` (effet système, sans_facture débrayable, exposée selon Gate facturation) ; « Quitter » (cloturer=false) ne touche pas au RDV. La durée (duree_secondes) est calculée par le serveur à la clôture, jamais acceptée du client — elle inclut la phase d'attente (divergence assumée avec le chrono « live » du front). Session terminée figée ; jamais de destroy.
