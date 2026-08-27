---
paths:
  - 'app/Models/{Sejour,SejourSoin,SejourConstante}*.php,app/Services/FactureSejour.php,app/Http/Controllers/Api/V1/{Sejour,SoinSejour,ConstanteSejour,SortieSejour}*.php,app/Http/Requests/V1/Hospitalisation/**'
---

# Hospitalisation

## Hospitalisation : domaine patients, séjours par clinique, sortie = clôture facturée
Séjours = CLINIQUE (le box est un lit physique, capacité cliniques.nombre_boxes ; occupation vérifiée sur les séjours en cours, pas d'unicité en base). Domaine de permission `patients` (l'ASV tient l'hospitalisation) ; les effets stocks/facturation des gestes sont des effets système sans domaine requis. Soins et constantes = enfants sans scope, résolus via le séjour ; heures LOCALES en minutes du jour (comme les horaires), pas des datetimes. « Soin fait » est irréversible et sort le stock via GestionnaireStock (rupture signalée, jamais bloquante). Jamais de destroy : la sortie renseigne sorti_le et facture via FactureSejour (forfait 65/95/140 CHF/j selon statut, perfusion 38/j, soin fait 15 — constantes de parité front, hors catalogue prestations, divergence assumée) ; jours = jours calendaires locaux couverts. Un séjour sorti ne se modifie plus. Vétérinaire référent = employé des cliniques de l'organisation (comme les signataires), affiché withoutGlobalScope.
