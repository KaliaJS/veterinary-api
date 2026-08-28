---
paths:
  - 'app/Models/RendezVous.php,app/Http/Resources/V1/RendezVousResource.php,database/seeders/RendezVousSeeder.php'
---

# Seeders

## Dérivés du rendez-vous servis par scope, seed en heure locale de clinique
RendezVousResource sert deux dérivés jamais stockés : `a_compte_rendu` (withExists compteRendu) et `no_shows_client` (count des noshow du client, toutes cliniques de l'organisation — homonymes distingués par la FK). Sur une liste, appeler `RendezVous::avecDerives()` (une requête pour la page) ; un modèle isolé les recalcule (2 requêtes) — ne pas dupliquer le calcul ailleurs. `GET /rendez-vous?actifs=1` écarte annulés et tampons (bilan d'impact d'une absence lit meta.total). Les minutes du seed sont des heures LOCALES de la clinique (fuseau_horaire) : toujours `CarbonImmutable::today($fuseau)->addMinutes()->utc()` — app.timezone est UTC, un `today()` nu décale tout de deux heures à l'écran.
