---
paths:
  - 'app/Http/Controllers/Api/V1/FactureLigne*.php,app/Http/Resources/V1/DevisResource.php,app/Models/Devis.php'
---

# Resources V1 Models

## Lignes de facture corrigeables tant que due ; TVA du devis
PATCH/DELETE /facturation/factures/{id}/lignes/{ligne} (FactureLigneController::update/destroy) : gardes GesteDeCaisse (facture DUE, clinique d'émission), ligne résolue via $facture->lignes()->findOrFail (jamais par binding). Quantité seule modifiable (libellé/prix viennent du catalogue). Une ligne MÉDICAMENT est figée dès que `stock_sorti` (payer-plus-tard) — la correction passe alors par un ajustement de stock ; un acte se corrige encore. Retirer une ligne médicament retire sa prescription jointe (mode stock, même nom). Une facture garde au moins une ligne (une facture vide se supprime via la fenêtre d'undo, ne s'encaisse pas). Chaque geste renvoie la facture complète.
Devis::montantTva = TVA extraite du TTC remisé au taux Facture::TAUX_TVA (même formule que Facture::montantTva) ; DevisResource l'expose en `montant_tva` quand les lignes sont chargées.
