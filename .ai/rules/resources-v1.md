---
paths:
  - 'app/Http/Controllers/Api/V1/{Patient,Client}*.php,app/Http/Resources/V1/{Patient,Client,AnnuaireEmploye}Resource.php'
---

# Resources V1

## Listes patients/clients : dérivés servis en liste (poids, facettes, prochain RDV, noms)
La liste patients sert ce que l'écran affiche sans ouvrir la fiche : `dernier_poids_kg` + `courbe_poids` (relation Patient::courbePoids, 12 dernières pesées via limite d'eager-load par parent — le show charge `pesees` en entier), `prochain_rendez_vous` (Patient::rendezVousAVenir : withoutGlobalScope clinique, à venir, statuts prevu/salle/cours, limit 1 — toutes cliniques de l'organisation, le patient étant de niveau organisation) et `meta.facettes` {tous, chien, chat, nac, alertes, rappels} calculées en UNE requête d'agrégats conditionnels sur la recherche `q` mais indépendantes du `filtre` actif (PatientController::facettes — garder la même définition que les filtres, horizon rappels = 30 j). La liste clients sert `noms_patients` (relation Client::patientsParNom) sans charger `patients` (réservé au show). L'annuaire planning porte `acces_suspendu` (état d'affichage, pas donnée RH) — rien d'autre de RH.
