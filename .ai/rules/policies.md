---
paths:
  - 'app/Http/Controllers/Api/V1/{OrganisationHoraire,AnnuaireEmploye}*.php,app/Policies/EmployePolicy.php'
---

# Policies

## Horaires d'une clinique sœur (organisation) et annuaire planning (sans RH)
Deux lectures/écritures qui traversent les frontières habituelles, chacune bornée explicitement :
- GET/PUT /organisation/cliniques/{id}/horaires (domaine `organisation`, OrganisationHoraireController) : horaires d'une clinique créée ou sœur, résolue depuis l'organisation du jeton (404 hors organisation), HoraireOuverture lu/créé withoutGlobalScope + withoutEvents (dérogation documentée à l'invariant d'écriture locale). Réutilise ModifierHorairesRequest et HoraireOuvertureResource — mêmes invariants que PUT /horaires. Ne pas y ajouter d'autre geste de structure.
- GET /planning/employes (domaine `planning`, ability EmployePolicy::viewAnnuaire, AnnuaireEmployeResource) : identité, initiales, titre, poste, ligne_planning_id de la clinique du jeton — JAMAIS de champ RH (contrat, salaire, congés, licenciement, accès), qui restent sous /equipe (domaine `equipe`). Sert à la grille pour les zones d'absence (lecture des absences = planning aussi).
