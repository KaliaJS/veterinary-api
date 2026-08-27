# Project Rules Index

Before planning or editing, find the row whose globs match the file's path and read that rule file.

| Applies to | Rule file |
| --- | --- |
| app/Http/Controllers/Api/V1/{Patient,Client,Pesee,Vaccination,Visite,Document,Releve,Carnet,Compte}*.php | .ai/rules/api-v1.md |
| app/Auth/**, app/Services/Passkey*.php, app/Policies/** | .ai/rules/auth.md |
| app/Models/{Intervention,InterventionChecklistPoint,InterventionTransition}*.php,app/Services/ProjectionPlanningBloc.php,app/Http/Controllers/Api/V1/{Intervention,CycleIntervention,ChecklistIntervention,ConsentementIntervention,Salle}*.php,app/Http/Requests/V1/Bloc/** | .ai/rules/bloc.md |
| app/Models/{Produit,Stock,MouvementStock,Lot,CommandeFournisseur}*.php,app/Services/GestionnaireStock.php,app/Http/Controllers/Api/V1/{Produit,MouvementStock,AjustementStock,CommandeFournisseur,Lot}*.php, app/Models/{Facture,Devis,ClotureCaisse,Ordonnance,Prestation}*.php,app/Services/{CreationFacture,EncaissementFacture,PrestationsParDefaut}.php,app/Http/Controllers/Api/V1/{Facture,Devis,CycleDevis,Encaissement,DecompteAssurance,ClotureCaisse,FinConsultation,Ordonnance,CycleOrdonnance,RendezVousStatut}*.php | .ai/rules/controllers-api-v1.md |
| app/Services/{FactureSejour,PrestationsParDefaut}.php,app/Enums/StatutSejour.php | .ai/rules/enums.md |
| app/Models/{Sejour,SejourSoin,SejourConstante}*.php,app/Services/FactureSejour.php,app/Http/Controllers/Api/V1/{Sejour,SoinSejour,ConstanteSejour,SortieSejour}*.php,app/Http/Requests/V1/Hospitalisation/** | .ai/rules/hospitalisation.md |
| routes/api.php,app/Http/** | .ai/rules/http.md |
| app/Models/{ExamenImagerie,ClicheDicom}*.php,app/Services/ProjectionPlanningImagerie.php,app/Http/Controllers/Api/V1/{ExamenImagerie,CompteRenduImagerie,ClicheDicom,TrameImagerie,ImageriePatient}*.php,app/Http/Requests/V1/Imagerie/** | .ai/rules/imagerie.md |
| app/Models/{Analyse,AnalysePanel,AnalyseResultat,PanelLabo,PanelParametre}*.php,app/Http/Controllers/Api/V1/{Analyse,CycleAnalyse,ResultatAnalyse,PanelLabo}*.php,app/Http/Requests/V1/Laboratoire/**,app/Services/PanelsLaboParDefaut.php | .ai/rules/laboratoire-services.md |
| database/migrations/** | .ai/rules/migrations.md |
| app/Models/** | .ai/rules/models.md |
| app/Models/Absence.php,app/Policies/AbsencePolicy.php,app/Services/CalculateurCreneaux.php | .ai/rules/services.md |
| app/Models/MouvementStupefiant.php,app/Http/Controllers/Api/V1/{MouvementStupefiant,RegistreStupefiant}*.php,app/Http/Requests/V1/Stupefiants/** | .ai/rules/stupefiants.md |
| app/Models/SessionTeleconsultation.php,app/Services/ProjectionPlanningTele.php,app/Http/Controllers/Api/V1/{SessionTeleconsultation,FinTeleconsultation}*.php,app/Http/Requests/V1/Tele/** | .ai/rules/tele.md |
| app/Http/Controllers/Api/V1/{EquipeEmploye,AccesEmploye,LicenciementEmploye,GardeWeekend,Organisation,Societe,Abonnement,PackQuota,MoyenPaiement,CommandeBoutique,VenteComptoir,Vitrine}*.php,app/Models/{Employe,Organisation,GardeWeekend,CommandeBoutique}.php | .ai/rules/v1-models.md |
| app/Services/**,app/Http/Controllers/Api/V1/RendezVous*.php | .ai/rules/v1.md |
