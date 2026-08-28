---
paths:
  - 'app/Services/GestionnaireStock.php,app/Http/Controllers/Api/V1/{AjustementStock,CommandeFournisseur,CommandeFournisseurLigne,Produit,AnnuaireClinique}*.php,app/Models/CommandeFournisseur.php'
---

# Api V1 Models

## Stocks : écritures vers une clinique sœur, commandes d'organisation, lignes de brouillon, annuaire cliniques
Révision de « écritures bornées à la clinique du jeton » : GestionnaireStock::appliquer/entrer/sortir acceptent `cliniqueId` — clinique du jeton par défaut, sinon une clinique SŒUR vérifiée dans l'organisation (AuthorizationException au-delà), Stock/MouvementStock créés withoutEvents (dérogation documentée). inverserMouvementsDe inverse chaque mouvement sur SA clinique. Les FormRequests exposent `clinique_id` (estUneCliniqueDeLOrganisation) pour l'ajustement, le stock initial et la clinique de livraison d'une commande (store et generer).
Commandes fournisseur : lecture et gestes sur TOUTES les cliniques de l'organisation (scope CommandeFournisseur::dansLOrganisation, résolution sans binding via CommandeFournisseurController::commandeDeLOrganisation, filtre `?clinique_id=`) — la réception crédite la clinique de LIVRAISON (stock et Lot, withoutEvents) ; `generer` considère couverts les brouillons de toute l'organisation (seuil = stock total). Lots : lecture/gestes restent clinique du jeton. Lignes : POST/PATCH/DELETE /stocks/commandes/{id}/lignes[/{ligne}] sur un BROUILLON seulement (422 `statut` sinon), pas de doublon de produit (422 `produit_id`), coût figé à l'ajout, réponse = commande complète.
GET /cliniques (AnnuaireCliniqueController, tout employé authentifié, aucun Gate) : id, nom, nombre_boxes seulement — la gestion reste sous /organisation.
