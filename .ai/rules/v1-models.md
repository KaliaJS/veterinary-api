---
paths:
  - 'app/Http/Controllers/Api/V1/{EquipeEmploye,AccesEmploye,LicenciementEmploye,GardeWeekend,Organisation,Societe,Abonnement,PackQuota,MoyenPaiement,CommandeBoutique,VenteComptoir,Vitrine}*.php,app/Models/{Employe,Organisation,GardeWeekend,CommandeBoutique}.php'
---

# V1 Models

## Équipe, organisation, boutique : scopes tranchés et effets système
Domaine `organisation` (7e DomainePermission) réservé à l'Administrateur par défaut. Les modèles de STRUCTURE (organisation, sociétés, cliniques) n'ont pas de global scope : les contrôleurs résolvent TOUJOURS depuis l'organisation du jeton, jamais par binding implicite. Gestes RH (fiche, embauche, suspension, licenciement) bornés à l'ORGANISATION — résolution sans binding (EquipeEmployeController::employeDeLOrganisation) ; la liste d'équipe reste clinique du jeton. Seules dérogations à l'invariant d'écriture locale (withoutEvents, documentées) : embauche multi-cliniques et horaires d'une clinique créée. Jamais de suppression d'employé — licenciement = état, suspension d'accès refusée par le middleware clinique à effet immédiat ; quota d'employés ACTIFS de la formule SaaS vérifié à l'embauche ET au rétablissement. Gardes de week-end : rotation DÉRIVÉE (semaine modulo effectif vétérinaire), seule une réattribution est stockée. Boutique = domaine stocks (l'ASV la tient) : la vente comptoir crée une facture payée de lignes produit comme EFFET SYSTÈME (pas de table ventes — les chiffres 7 jours en dérivent), la remise d'une commande en ligne sort le stock de la clinique de retrait, prix vitrine figé à la commande (promo comprise). Pièces : F- (abonnement, annuelle), C- (commande boutique, sans année) — toujours via Numeroteur.
