---
paths:
  - 'app/Models/{ExamenImagerie,ClicheDicom}*.php,app/Services/ProjectionPlanningImagerie.php,app/Http/Controllers/Api/V1/{ExamenImagerie,CompteRenduImagerie,Radio,TrameImagerie,ImageriePatient}*.php,app/Http/Requests/V1/Imagerie/**,app/Services/Patients/RecordShareItems.php'
---

# Imagerie

## Imagerie : l'examen est maître, le RDV imagerie n'est que sa projection planning
Même patron que le bloc, TRANCHÉ : l'examen (clinique, domaine `patients`) est le dossier maître ; rendez_vous_id nullable unique (RDV `imagerie` non annulé de la clinique, pas déjà maître d'un autre examen) et la sync est À SENS UNIQUE via ProjectionPlanningImagerie → MutationRendezVous (auditée) : enregistrement/reprogrammation poussent realise_le/durée/patient, la signature du CR pousse `fini` + facture de l'acte `imagerie` (effet système, sans_facture débrayable, exposée selon le Gate facturation) — rien ne remonte du planning. La signature (POST /compte-rendu, constatations obligatoires, signataire = employé du jeton) FIGE l'examen : une seule signature, plus aucun PATCH. `done` du front = signe_le non null, jamais stocké. Clichés DICOM = enfants via l'examen, IDEMPOTENTS par sop_instance_uid (unique en base) ; ils n'arrivent que par leur fichier (règle suivante). Fiche patient toutes cliniques : GET /patients/{id}/imagerie (remplace patients[].imagings). Trames de CR = référentiel applicatif sur ModaliteImagerie::trame().

## Une radio n'entre que par son fichier ; jamais de chemin fourni par le client
Décision de Jim, 13.11.2026 (audit C03) : l'ancienne déclaration de cliché par métadonnées (POST examens/{examen}/cliches, champ `chemin` libre) est supprimée. Une radio n'arrive que par POST examens/{examen}/cliches/fichier (RadioController), qui range l'original sous imagerie/{organisation}/{examen}/{sop}.dcm et pose taille_octets. Ne jamais accepter un chemin de fichier venu du client, ni le renvoyer au navigateur (ClicheDicomResource n'expose pas `chemin`). Tout service qui sert le fichier d'un cliché passe par ClicheDicom::cheminDeLOriginal() (taille_octets non nul, chemin sous imagerie/{organisation}/{examen}/, sans « .. ») : RecordShareItems::file (partage, lien public, référés) et RadioDossierController::fichier (visionneuse).
