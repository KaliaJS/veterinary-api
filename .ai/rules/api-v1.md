---
paths:
  - 'app/Http/Controllers/Api/V1/{Patient,Client,Pesee,Vaccination,Visite,Document,Releve,Carnet,Compte}*.php'
---

# Api V1

## Domaine patients : niveau organisation, dossier via le patient
Patients et clients sont de NIVEAU ORGANISATION (trait AppartientAUneOrganisation) : partagés entre les cliniques d'un même jeton d'organisation — jamais de filtre clinique. Jamais de suppression de fiche patient/client (pas de destroy) ; suppressions tolérées : pesée erronée, relevé ophtalmologique. Les enfants du dossier (pesées, vaccinations, visites, documents, relevés, dents) n'ont AUCUN scope propre : ils se résolvent toujours via le patient ($patient->relation()->findOrFail(...)), jamais par binding direct. Le compte rendu SOAP suit son rendez-vous (scoppé CLINIQUE) — un par RDV, upsert au PUT, colonnes s/o/a/p du front. Pesée du jour et injection du même vaccin font un upsert, pas un doublon. Dérivés jamais stockés : alertes (agressif/allergies/solde), solde dû, no-shows (toutes cliniques de l'organisation), client_depuis min. Signataires (vaccination/visite) = employé du contexte ; à l'affichage, lever le scope clinique de l'employé (withoutGlobalScope) car il peut être d'une clinique sœur.
