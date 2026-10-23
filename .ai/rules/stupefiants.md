---
paths:
  - 'app/Models/MouvementStupefiant.php,app/Http/Controllers/Api/V1/{MouvementStupefiant,RegistreStupefiant}*.php,app/Http/Requests/V1/Stupefiants/**'
---

# Stupefiants

## Registre des stupéfiants : immuable, PAR clinique, patient FK en sortie
Domaine stocks (art. 62 OCStup). Substances = produits `est_stupefiant` (unité ml) ; quantités décimales SIGNÉES (+ réception / − sortie), jamais liées à `stocks`/GestionnaireStock (dimensions différentes : le stock compte des flacons, le registre des ml). Journal immuable (UPDATED_AT null, création seule) : une erreur se corrige par un mouvement inverse ; le solde = Σ mouvements de la clinique (le solde de départ est un mouvement « report d'inventaire »), alerte sous MouvementStupefiant::SEUIL_STOCK_BAS. Contrairement au journal de stock, lecture PAR CLINIQUE (le registre est tenu et présenté par la clinique) — pas de withoutGlobalScope. Patient : FK d'organisation, OBLIGATOIRE en sortie, ignoré (null) en réception, parité front. Signataire = TOUJOURS l'employé du jeton, droit `stupefiants` exigé (23.10.2026) ; une sortie ne dépasse jamais le solde (verrou sur le produit) ; le modèle lève une LogicException à toute modification ou suppression.
