---
paths:
  - 'app/Models/{Intervention,InterventionChecklistPoint,InterventionTransition}*.php,app/Services/ProjectionPlanningBloc.php,app/Http/Controllers/Api/V1/{Intervention,CycleIntervention,ChecklistIntervention,ConsentementIntervention,Salle}*.php,app/Http/Requests/V1/Bloc/**'
---

# Bloc

## Bloc : l'intervention est maître, le RDV chir n'est que sa projection planning
Divergence « RDV chir vs modèle parallèle » TRANCHÉE : l'intervention (clinique, domaine `bloc`) est le dossier maître ; rendez_vous_id nullable (RDV chir non annulé de la clinique) et la sync est À SENS UNIQUE via ProjectionPlanningBloc → MutationRendezVous (donc auditée) : reprogrammation pousse starts/ends/salle/patient, démarrage → RDV cours, clôture → RDV fini + facture de l'acte chir (effet système, sans_facture débrayable) — rien ne remonte du planning vers le bloc. Cycle prevu→prep→cours→reveil→fini un cran à la fois, jamais de destroy ; `cours` exige la checklist complète (6 points de base + ajoutés) et pose incision_a ; `consent` ne se coche QUE par POST /consentement (Document type consentement au dossier patient + horodatage). Terminée : seule la note opératoire bouge. salle_nom dénormalisé : DELETE /salles ne vaut que pour une salle de bloc sans intervention en cours, sans ligne de planning, jamais la dernière de la clinique (interventions finies → salle_id null, nom conservé).
