---
paths:
  - 'app/Services/{FactureSejour,PrestationsParDefaut}.php,app/Enums/StatutSejour.php'
---

# Enums

## Tarifs de séjour : au catalogue de prestations, plus jamais en constantes PHP
La divergence « tarifs d'hospitalisation hors catalogue » est RÉSOLUE (remplace la mention « divergence assumée » de la règle hospitalisation) : les forfaits journaliers (hosp_stable 65 / hosp_surv 95 / hosp_critique 140), la perfusion (hosp_perfusion 38) et le soin (hosp_soin 15) sont des prestations du catalogue d'organisation — FactureSejour lit le prix courant (firstOrFail), StatutSejour ne porte que codePrestation(). Deux catalogues divergents ont déjà produit deux prix pour les mêmes actes : tout nouveau tarif métier va dans `prestations` (PrestationsParDefaut + migration de backfill idempotente pour les organisations existantes), jamais dans une constante.
