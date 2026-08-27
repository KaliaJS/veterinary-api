---
paths:
  - 'app/Models/{ExamenImagerie,ClicheDicom}*.php,app/Services/ProjectionPlanningImagerie.php,app/Http/Controllers/Api/V1/{ExamenImagerie,CompteRenduImagerie,ClicheDicom,TrameImagerie,ImageriePatient}*.php,app/Http/Requests/V1/Imagerie/**'
---

# Imagerie

## Imagerie : l'examen est maître, le RDV imagerie n'est que sa projection planning
Même patron que le bloc, TRANCHÉ : l'examen (clinique, domaine `patients`) est le dossier maître ; rendez_vous_id nullable unique (RDV `imagerie` non annulé de la clinique, pas déjà maître d'un autre examen) et la sync est À SENS UNIQUE via ProjectionPlanningImagerie → MutationRendezVous (auditée) : enregistrement/reprogrammation poussent realise_le/durée/patient, la signature du CR pousse `fini` + facture de l'acte `imagerie` (effet système, sans_facture débrayable, exposée selon le Gate facturation) — rien ne remonte du planning. La signature (POST /compte-rendu, constatations obligatoires, signataire = employé du jeton) FIGE l'examen : une seule signature, plus aucun PATCH. `done` du front = signe_le non null, jamais stocké. Clichés DICOM = enfants via l'examen, métadonnées seules (comme les documents), flux IDEMPOTENT par sop_instance_uid (firstOrCreate, unique en base). Fiche patient toutes cliniques : GET /patients/{id}/imagerie (remplace patients[].imagings). Trames de CR = référentiel applicatif sur ModaliteImagerie::trame().
