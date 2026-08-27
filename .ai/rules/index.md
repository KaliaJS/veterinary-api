# Project Rules Index

Before planning or editing, find the row whose globs match the file's path and read that rule file.

| Applies to | Rule file |
| --- | --- |
| app/Http/Controllers/Api/V1/{Patient,Client,Pesee,Vaccination,Visite,Document,Releve,Carnet,Compte}*.php | .ai/rules/api-v1.md |
| app/Auth/**, app/Services/Passkey*.php, app/Policies/** | .ai/rules/auth.md |
| app/Models/{Produit,Stock,MouvementStock,Lot,CommandeFournisseur}*.php,app/Services/GestionnaireStock.php,app/Http/Controllers/Api/V1/{Produit,MouvementStock,AjustementStock,CommandeFournisseur,Lot}*.php, app/Models/{Facture,Devis,ClotureCaisse,Ordonnance,Prestation}*.php,app/Services/{CreationFacture,EncaissementFacture,PrestationsParDefaut}.php,app/Http/Controllers/Api/V1/{Facture,Devis,CycleDevis,Encaissement,DecompteAssurance,ClotureCaisse,FinConsultation,Ordonnance,CycleOrdonnance,RendezVousStatut}*.php | .ai/rules/controllers-api-v1.md |
| routes/api.php,app/Http/** | .ai/rules/http.md |
| database/migrations/** | .ai/rules/migrations.md |
| app/Models/** | .ai/rules/models.md |
| app/Models/Absence.php,app/Policies/AbsencePolicy.php,app/Services/CalculateurCreneaux.php | .ai/rules/services.md |
| app/Http/Controllers/Api/V1/{EquipeEmploye,AccesEmploye,LicenciementEmploye,GardeWeekend,Organisation,Societe,Abonnement,PackQuota,MoyenPaiement,CommandeBoutique,VenteComptoir,Vitrine}*.php,app/Models/{Employe,Organisation,GardeWeekend,CommandeBoutique}.php | .ai/rules/v1-models.md |
| app/Services/**,app/Http/Controllers/Api/V1/RendezVous*.php | .ai/rules/v1.md |
