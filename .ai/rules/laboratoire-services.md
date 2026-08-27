---
paths:
  - 'app/Models/{Analyse,AnalysePanel,AnalyseResultat,PanelLabo,PanelParametre}*.php,app/Http/Controllers/Api/V1/{Analyse,CycleAnalyse,ResultatAnalyse,PanelLabo}*.php,app/Http/Requests/V1/Laboratoire/**,app/Services/PanelsLaboParDefaut.php'
---

# Laboratoire Services

## Laboratoire : demandes par clinique, référentiel par organisation, bornes instantanées, validation signée
Demandes d'analyses = CLINIQUE (worklist du labo physique) ; la fiche patient lit toutes les cliniques de l'organisation (GET /patients/{id}/analyses, withoutGlobalScope('clinique') borné par le patient). Domaine `patients`. Numéro A-YY-NNNN UNIQUEMENT via Numeroteur (PieceNumerotee::AnalyseLabo, année courte). Panels = référentiel PAR ORGANISATION (PanelsLaboParDefaut, né avec elle comme les prestations — seeders et backfill l'appellent explicitement) ; la demande fait un INSTANTANÉ des panels et bornes (analyse_panels/analyse_resultats, lignes créées vides à la demande, dans l'ordre demandé, pas celui de l'index SQL). Cycle attente→cours→valid→ok un cran à la fois, jamais de destroy : saisie des valeurs en cours/valid seulement, drapeau H/L DÉRIVÉ (DrapeauResultatLabo::depuis), passage à valider bloqué tant qu'une valeur manque, `ok` = signature (valide_par = employé du jeton) qui fige tout — seule la note reste modifiable. Prescripteur = employé du jeton (le « Dr A. Meyer » codé en dur au front vient de l'auth).
