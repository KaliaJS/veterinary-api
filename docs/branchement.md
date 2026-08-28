# Branchement du front sur l'API — inventaire et plan

État des lieux établi le 27.08.2026, à partir des 26 fichiers de
`../veterinary-web/src/stores/` (24 stores Pinia + 2 modules de seed)
lus intégralement, et des 159 routes de `php artisan route:list`.

Constats transversaux qui conditionnent tout le plan :

- **Aucune couche HTTP** au front, à une exception près :
  `src/api/auth.js`, frontière mock documentée dont seules les
  implémentations sont à remplacer (signatures et formes de retour
  stables).
- **Aucune persistance métier.** Deux clés localStorage en tout
  (`vet-theme`, `vet-navoff`, plus `vet-sbw` dans un composant) ;
  tout le reste est reconstruit depuis des seeds à chaque
  rechargement. Il n'y a donc pas de migration de données locales à
  prévoir : la bascule est un remplacement de source, pas une
  synchronisation.
- **Toutes les numérotations sont clientes et fragiles**
  (`FC-`/`Z-`/`DV-`/`RX-`/`BC-`/`ART-`/`F-`/`A-` : `longueur du
  tableau + offset` ou compteur de module remis à zéro au reload).
  Côté API, tout passe déjà par `Numeroteur` (séquence annuelle par
  organisation, sous verrou) : le front doit cesser de fabriquer des
  numéros et afficher ceux que le serveur renvoie.
- **L'identité de l'auteur est codée en dur** (« Dr A. Meyer » dans
  patients, records, imaging, labs, tools, equipe…) : elle devra venir
  de `GET /auth/moi` partout.

---

## 1. Inventaire store par store

### 1.1 Stores d'interface — restent locaux, aucun endpoint

| Store | Rôle | Décision |
|---|---|---|
| `theme.js` | Thème visuel (`vet-theme`) | Reste localStorage. |
| `toast.js` | Notification unique + undo 10 s | Reste local ; voir §2.3 (undo) pour l'articulation avec les fenêtres serveur. |
| `prefs.js` | Langue du site vitrine | Reste local. Doublonne `settings.lang` — à unifier au front, pas de ressource API. |

### 1.2 Authentification

**`auth.js`** → `POST /auth/email` (compte connu), `POST
/auth/passkey/options` + `/auth/passkey/verifier`, `POST
/auth/code-email/envoyer` + `/verifier`, `POST /auth/deconnexion`.

- Servi mais non consommé : `GET /auth/moi` (restauration de session —
  indispensable, le mock perd la session au rechargement, choix
  assumé du proto), `POST /auth/rafraichir` (jetons 24 h), toute la
  gestion des passkeys enregistrées (`GET/POST/DELETE /auth/passkeys`
  + `/auth/passkeys/options`) — aucun écran front.
- Trou front : le store ne connaît ni jeton, ni clinique de
  rattachement, ni profil employé ; le user est fabriqué depuis
  l'e-mail. Le mock accepte n'importe quel code à 6 chiffres et
  renvoie `demoCode` — la vraie API ne le fait pas (hachage, usage
  unique).
- Session servie (`GET /auth/moi`, corps de toute émission de
  jeton) : `user { id, mail, first, last }`, `employe { id, nom,
  initiales, titre, role_libelle, role_id }` — l'auteur des gestes,
  à substituer au « Dr A. Meyer » codé en dur —, `clinique`,
  `cliniques` (tous les rattachements) et `permissions` effectives.
  `titre` (« Dr ») est dérivé du nom, le modèle ne le sépare pas.
- Multi-clinique : sans `clinique_id`, `POST /auth/code-email/verifier`
  et `POST /auth/passkey/verifier` répondent 200 `{ choix_clinique:
  true, cliniques: [{ id, nom }] }` sans jeton ; le code (ou le défi
  passkey) n'est **pas** consommé — le front rejoue la même requête
  avec `clinique_id`. Un `clinique_id` étranger au compte reste un 422.
- Changement de clinique : `POST /auth/clinique { clinique_id }` ré-émet
  un jeton vers un autre rattachement (réponse = session complète avec
  `token`) et révoque l'ancien.
- Passkeys en dev : WebAuthn est lié au domaine du **front** (RP ID et
  origines déduits de `FRONTEND_URL` côté API, défaut
  `http://localhost:5173`) — voir `.env.example`.

### 1.3 Organisation, équipe, réglages

**`org.js`** → `GET /organisation` (formule, quotas, usage), `POST
/organisation/abonnement` + `/resilier` + `/reactiver`, `POST
/organisation/packs`, `PUT/DELETE /organisation/moyen-paiement`,
`GET /organisation/factures` (F-), `GET/POST /organisation/societes`,
`GET/POST/PATCH/DELETE /organisation/cliniques`. Le volet « équipe par
clinique » (accès, embauche, licenciement) est servi par le domaine
équipe : `POST /equipe/employes` (embauche), `POST
…/{employe}/suspendre` / `/retablir` / `/licencier` /
`/annuler-licenciement`.

- Trous API : upload du logo de clinique (aucune route dédiée) ;
  flux d'embauche par QR / annuaire de comptes vérifiés (au front,
  `ACCOUNT_DIR` simulé — l'API attend directement un
  `POST /equipe/employes`, le cérémonial QR est à simplifier ou à
  construire) ; génération des documents RH de sortie (certificat de
  travail, attestations) — le licenciement API pose les états, pas
  les PDF.
- À abandonner au front : grille tarifaire, TVA 8,1 %, plafonds de
  quotas et paiement simulés côté client (le serveur fait foi),
  numéro `F-` fabriqué (`'F-2026-08' + longueur` — collisions
  garanties).

**`equipe.js`** (état d'écran, données déléguées à `planning.staff` /
`planning.absences`) → `GET /equipe/employes` (effectif, soldes),
`GET/POST/PUT/PATCH/DELETE /absences`, `POST /absences/{id}/valider` +
`/refuser`, `GET/PUT /equipe/gardes`.

- La rotation de gardes calculée au front (modulo semaine epoch,
  `gSwap` non persisté) est remplacée par la ressource `gardes`
  persistée ; les soldes de congés (`droit`/`taken`/`planned`)
  deviennent des champs servis.
- La validation d'absence annule les RDV de la période **côté
  serveur** (transactionnel, audité) : le front n'orchestre plus
  `cancelForAbsence`.

**`settings.js`** → `GET/PUT /horaires` (par clinique — voir §2.4).

- Trous API : réglages **rappels & messages** (`remMail`, `remSms`,
  `remDelay`, `remVac`, `remCtrl`, `remNoShow`, `msgIn`,
  `msgReplyOnly`) — aucune route, alors que le modèle de données les
  classe « réglages de clinique » ; mode `clinique`/`solo` — aucune
  route non plus. À construire ou à assumer localement en attendant.
- Restent locaux : `navOff` (préférence d'interface), langue/région/
  format de date (l'i18n réelle viendra avec).

### 1.4 Dossier patient

**`patients.js`** (+ `patients.seed.js`, qui disparaît) →
`GET/POST/PUT/PATCH /patients` (recherche, filtres et tri passent
serveur, paginés), `POST/PATCH/DELETE /patients/{id}/pesees`,
`GET/POST …/vaccinations`, `GET/POST …/visites`, `GET/POST
…/documents`, CRUD `…/releves-ophtalmologiques`, `GET/PUT …/dents`,
`PUT …/puce`, `POST …/amicus`.

- Trous API : la visionneuse DICOM et `patients[].imagings` n'ont
  aucune ressource (voir §2.1 imagerie) ; `toggleReminders` (rappels
  coupés par patient, `remOff`) n'a pas de route.
- À abandonner au front : génération du numéro de puce et du lot
  vaccinal (`Math.random`), calcul des échéances vaccinales, alerte
  `solde` dérivée de `debt` (l'API sert les dérivés : alertes, solde
  dû, no-shows, `client_depuis`).

**`owners.js`** → `GET/POST/PUT/PATCH /clients`, `POST/DELETE
/clients/{id}/compte` (compte espace client — remplace le
`hasAccount()` par hash du nom utilisé par les devis).

- La construction des propriétaires par agrégation des patients
  (`ownerIndex`, index de tableau, négatif pour les créés) disparaît :
  vraie relation client 1—N patients, champs `ownerName`/`phone`/
  `mail` du patient réduits à des dénormalisations servies.

**`records.js`** → `GET/PUT /rendez-vous/{id}/compte-rendu` (SOAP,
upsert), carnet : `GET/POST /patients/{id}/vaccinations`, `PUT
…/puce`, `POST …/amicus`. PDF carnet/CR restent générés au front.

- Trou API : `remindRow` (envoi manuel d'un rappel SMS + e-mail) n'a
  pas de route.
- Les trois maps indexées par la clé texte `nom|propriétaire`
  (`carnetMap`, `chipMap`, `amicus`) et le carnet « fabriqué par
  hash » sont remplacés par les vraies ressources patient (voir §2.5).

**`tools.js`** → couvert partiellement : schéma dentaire `GET/PUT
/patients/{id}/dents`, AMICUS `POST /patients/{id}/amicus`, recherche
patients `GET /patients?…`.

- Trous API : **certificats** (santé/rage/voyage/euthanasie — le
  toast « archivé au dossier » n'écrit nulle part, ni au front ni à
  l'API), **registre des stupéfiants** (exigence légale de
  traçabilité : mouvements immuables, auteur, patient lié — au front
  tout est en mémoire et le patient est une chaîne libre),
  référentiels (formulaire de doses, calculateurs) — peuvent rester
  embarqués côté front, les formules sont pures.

### 1.5 Planning

**`planning.js`** (+ `planning.seed.js`, qui disparaît, générateur
procédural de journées compris) →
`GET/POST/PUT/PATCH /rendez-vous`, `POST /rendez-vous/{id}/statut` +
`/annuler` + `/non-venu` + `/deplacer` + `/redimensionner`, `DELETE
/rendez-vous/{id}` (fenêtre d'undo de création), `GET
/rendez-vous/{id}/audits`, `GET /planning/disponibilites`, `POST
/rendez-vous/prochain-creneau`, `GET/POST/DELETE /urgences` +
`/placer`, `GET/DELETE /demandes` + `/placer`, `GET/POST/DELETE
/liste-attente` + `/placer`, `GET /lignes-planning`, `GET /salles`,
`GET /absences`, `GET/PUT /horaires`.

- Servi mais non consommé aujourd'hui : `POST/PUT /lignes-planning`
  et `POST/PUT /salles` (au front, lignes et salles sont des
  constantes non éditables) ; `disponibilites` et `prochain-creneau`
  (le front calcule `freeGaps`/`nextFree`/`demSlots` localement — à
  remplacer, la concurrence multi-postes l'exige).
- Nuance sur les demandes en ligne : le front distingue
  placer / confirmer / **proposer trois créneaux** / refuser ; l'API
  n'a que `placer` et `DELETE`. « Proposer » peut se composer avec
  `GET /planning/disponibilites`, mais l'envoi de la proposition au
  client n'a pas de route.
- Le compteur de no-show indexé **par nom de propriétaire** disparaît
  (dérivé serveur par client, toutes cliniques). La facture créée au
  passage « fini » est un effet serveur du `POST …/statut`.
- Restent front : l'état d'affichage (zoom, aimant, groupes repliés,
  sélection), le calcul de `charge %` et les compteurs de la cloche
  (dérivables des données servies).

### 1.6 Facturation, devis, ordonnances, fin de consultation

**`invoices.js`** → `GET/POST /facturation/factures`, `GET/DELETE
…/{facture}` (delete = fenêtre d'undo), `POST …/lignes`, `POST
…/prescriptions`, `POST …/encaisser`, `POST …/payer-plus-tard`, `PUT
…/decompte` + `POST …/decompte/avancer`, `GET/POST
/facturation/clotures-caisse` + `GET …/{cloture}`, `GET
/facturation/prestations` (catalogue unifié — remplace `ACTES`), `GET
/facturation/statistiques`. PDF/CSV restent générés au front.

- À abandonner : numéros `FC-`/`Z-` clients, drapeau `stocked`
  (l'idempotence de la sortie de stock est une contrainte serveur),
  décrément direct de `p.stock.c1`, TPE/TWINT simulés par
  `setTimeout` (l'encaissement API enregistre le paiement ; le vrai
  PSP est hors périmètre).

**`devis.js`** → CRUD `/facturation/devis` (`DELETE` = brouillon),
`POST …/envoyer` + `/accepter` + `/refuser` + `/dupliquer` +
`/facturer-acompte` + `/facturer-finale`.

- Trou API : `relance` (aucune route — au front c'est déjà un toast
  décoratif). L'expiration (`expire` dérivé) et le choix du canal
  portail/papier (compte client réel, plus un hash du nom) passent
  serveur. Le lien devis → factures par **numéro texte**
  (`acompteInv`) devient un lien par id.

**`ordos.js`** → `GET/POST /ordonnances`, `GET/DELETE
…/{ordonnance}` (delete = fenêtre d'annulation), `POST …/cloturer` +
`/renouveler`.

- La signature avec sortie de stock immédiate est servie ; les bugs
  front (renouvellement sans contrôle du compteur, undo qui recrée du
  stock borné à zéro, `_stockDec` dupliqué avec finconsult)
  disparaissent avec la bascule.

**`finconsult.js`** → `POST /rendez-vous/{id}/fin-de-consultation`
(transaction serveur : actes + vaccin au carnet + ordonnance au
registre + sortie de stock + facture + statut `fini`), `skip()` →
`POST /rendez-vous/{id}/statut`. Catalogue `FC_CATALOG` remplacé par
`GET /facturation/prestations`.

- C'est le store le plus couplé du front (7 stores importés) et son
  undo est un rollback à base de snapshots JS : l'atomicité passe
  entièrement côté serveur.

### 1.7 Stocks et boutique

**`products.js` + `stocks.js`** → `GET/POST /stocks/produits`, `GET
…/{produit}`, `POST …/ajuster`, `GET …/mouvements` (remplace le
générateur d'historique **fictif** `movementsOf`), `GET/POST
/stocks/commandes` + `POST /generer` + `DELETE` + `POST /envoyer` +
`POST /receptionner`, `GET /stocks/lots` + `POST …/ecarter`.

- Le stock cesse d'être un champ muté par cinq stores : lecture de la
  ventilation par clinique servie (transversale à l'organisation),
  écritures uniquement par les gestes API (chaque geste laisse un
  mouvement immuable). La réception saisit lot et péremption réels
  (au front, date `'06.2028'` inventée en dur) et devient idempotente.
- Trou API mineur : pas de modification/suppression de produit
  (le front n'en a pas non plus — cohérent).

**`shop.js`** → `GET/POST /boutique/commandes`, `POST …/preparer` +
`…/retirer` (sortie de stock au retrait — voir §2.6), `POST
/boutique/vente` (vente comptoir), `PATCH
/boutique/produits/{id}/vitrine` (promo/star/en ligne), `GET
/boutique/statistiques`.

- Les commandes seedées du front sont des chaînes libres (« 2×
  Croquettes… », total texte, client par nom, produits fantômes hors
  catalogue) : la ressource API impose des lignes structurées
  (`produit_id`, quantités) et un client identifié.
- Trou API : la notification client à « prête au retrait » (toast au
  front) n'a pas de canal réel.
- Le journal `sales` parallèle et la TVA calculée **en sus** (alors
  que la caisse l'extrait du TTC) disparaissent : statistiques et
  fiscalité servies par une source unique.

### 1.8 Domaines front sans aucune contrepartie API

Quatre stores complets et deux registres n'ont **aucune route** — ils
ne peuvent pas être basculés et restent sur seeds tant que leurs
domaines API n'existent pas :

| Front | Manque côté API |
|---|---|
| `bloc.js` | Interventions, cycle prevu→prep→cours→reveil→fini, checklist de sécurité, consentement signé. Les salles de bloc, elles, sont servies (`salles` typée `bloc`) — mais sans `DELETE`, que l'écran front propose. |
| `hosp.js` | Séjours, box, plan de soins (avec décrément de stock au soin), constantes, admission, sortie avec facture de séjour. |
| `tele.js` | Sessions de téléconsultation (signalisation visio, durée). Les effets de fin (statut `fini` → facture, CR) sont servis via le planning. |
| `imaging.js` | Worklist d'imagerie, comptes rendus signés, flux DICOM. Rien non plus pour `patients[].imagings`. |
| `labs.js` | Demandes d'analyses (`A-YY-NNNN`), cycle attente→cours→valid→ok, résultats par panel avec bornes. |
| `tools.js` (partiel) | Certificats PDF archivés au dossier ; registre des stupéfiants (légalement le plus urgent des manques). |

À l'inverse, les RDV de type `chir`/`imagerie`/`tele` existent au
planning des deux côtés : la divergence « RDV du planning vs modèle
métier parallèle (interventions, examens) » reste ouverte tant que ces
domaines ne sont pas construits.

### 1.9 Servi par l'API, sans usage front aujourd'hui — récapitulatif

**À adopter pendant la bascule** (remplacent un calcul ou un seed
local) : `GET /auth/moi`, `POST /auth/rafraichir`, `GET
/planning/disponibilites`, `POST /rendez-vous/prochain-creneau`, `POST
/rendez-vous/{id}/fin-de-consultation`, `GET /facturation/prestations`,
`GET /facturation/statistiques`, `GET /boutique/statistiques`,
`GET/PUT /equipe/gardes`, `GET/PUT /horaires`, `POST/DELETE
/clients/{id}/compte`, `GET /rendez-vous/{id}/audits` (le front garde
son propre journal en mémoire).

**Sans écran ni geste front — à décider (écran à créer ou API en
avance de phase)** : gestion des passkeys enregistrées, `POST
/patients/{id}/visites` (aucune saisie de visite au front), `POST
/patients/{id}/documents` (aucun téléversement), `POST/PUT
/lignes-planning` (lignes statiques au front), `POST/PUT /salles`,
`POST /facturation/factures` (création libre hors RDV/séjour/devis).

---

## 2. Divergences connues — implications côté Vue

### 2.1 Deux équipes à fusionner

L'API tranche : une table `employes` unique, exposée par `GET
/equipe/employes` ; l'écran Organisation et le planning en sont deux
vues. Côté Vue :

- `org.clinics[].team` (membres par **nom**, sans id, adressés par
  **index de tableau** dans le licenciement, dupliqués en N copies à
  l'embauche multi-cliniques) et `planning.staff` (ids `st*`) doivent
  lire la **même** ressource. Concrètement : un seul état « employés »
  (dans le store équipe ou un store dédié), `org` et `planning` le
  consomment.
- Tous les gestes RH (suspendre, rétablir, embaucher, licencier)
  passent par les endpoints employé et se reflètent automatiquement
  dans l'écran Équipe **et** le planning — au front actuel, embaucher
  via Organisation n'ajoute personne au planning.
- Le raccord des deux jeux de seeds (« Dr A. Meyer » vs « Dr Anna
  Meyer », « J. Muller » en `c2` côté org mais `c1` côté planning)
  n'a plus à être fait au front : le seed serveur fait foi.
- `staff.laneId` (lien employé → ligne de planning) et `clinique_id`
  sont servis ; `equipe.js` cesse d'écrire directement dans
  `planning.absences`.

### 2.2 `role` → `role_libelle`

Côté API, `role` du front devient `role_libelle` (libellé d'affichage
libre : « Vétérinaire », « ASV · Accueil ») **distinct** du rôle
configurable qui porte les permissions (`role_id`, six domaines,
vérifiés en base à chaque requête). Côté Vue :

- Renommage mécanique du champ dans tout ce qui affiche le poste
  (écrans Équipe, Organisation, fiches).
- Tout ce qui **filtre par la chaîne** doit changer de base : la
  rotation de gardes front (`role === 'Vétérinaire'`), la liste
  `SURGEONS` du bloc, les signataires par nom. Le serveur fait déjà
  ces sélections (`RotationGardes` filtre sur `role_libelle` en
  préfixe) ; le front consomme le résultat.
- Les menus masqués par profil (`navHidden` esquissé au proto)
  doivent se brancher sur les **permissions effectives** renvoyées
  par `GET /auth/moi`, pas sur le libellé. Prévoir aussi le
  traitement des 403 : une ASV sans domaine `facturation` verra ses
  requêtes refusées même si un menu reste visible — masquage UI et
  garde serveur sont deux couches distinctes.

### 2.3 (transversal aux divergences) Undo 10 s

Le front annule des gestes composés par snapshots JS. Côté API, l'undo
est soit une **fenêtre de suppression** (création de RDV : 20 s, même
auteur, aucun autre geste ; facture ; ordonnance ; devis brouillon…),
soit un **mouvement inverse** (stock). Le `toast.sayUndo` reste, mais
son rappel doit appeler l'endpoint d'annulation correspondant et
accepter un refus (fenêtre expirée, autre geste intervenu) — l'état
local ne peut plus être restauré par closure.

### 2.4 Horaires par clinique

L'API a une source unique : `horaires_ouverture` **par clinique**,
typée, en **heures locales** avec `fuseau_horaire` par clinique
(`GET/PUT /horaires` pour la clinique du jeton ; `GET/PUT
/organisation/cliniques/{id}/horaires`, domaine `organisation`, pour
une clinique créée ou sœur depuis l'écran Organisation). La grille
lit l'annuaire de la clinique par `GET /planning/employes` (identité,
ligne, poste — domaine `planning`, sans les données RH d'`/equipe`)
pour dessiner les zones d'absence même sans le domaine `equipe`.
Côté Vue :

- `settings.js` cesse d'être un jeu d'horaires global : chargé depuis
  l'API pour la clinique du jeton, sauvegardé par `PUT /horaires`. Le
  planning (`openOf`/`closeOf`/`pauseOf`) et le calcul des zones
  « FERMÉ » consomment cette donnée sans changement de forme si le
  front garde sa conversion en minutes.
- Les chaînes libres `hSem`/`hSam`/`hDim` de l'écran Organisation
  deviennent un **rendu** de la même ressource — plus jamais un champ
  éditable indépendant.
- Les invariants du stepper (fermeture ≥ ouverture + 2 h, bornes de
  pause…) sont validés côté serveur : l'UI peut garder ses gardes de
  confort mais doit afficher les 422.
- Attention aux fuseaux : les RDV restent en UTC, les horaires en
  heures locales de la clinique — le front doit convertir avec le
  fuseau servi, pas avec celui du navigateur.

### 2.4 bis Stocks : ventilation transversale, écritures vers une clinique sœur

Le catalogue est d'organisation et la ventilation des quantités se lit
toutes cliniques ; les **écritures** visent la clinique du jeton par
défaut, ou une clinique sœur explicite (`clinique_id` sur
`POST /stocks/produits/{id}/ajuster`, sur le stock initial de
`POST /stocks/produits`, et comme clinique de livraison de
`POST /stocks/commandes` et `/commandes/generer`) — jamais une autre
organisation. Les commandes fournisseur se lisent et se pilotent depuis
toute clinique de l'organisation (`GET /stocks/commandes?clinique_id=`
pour filtrer) ; la réception crédite la clinique de livraison. Les
lignes d'un **brouillon** se modifient (`POST/PATCH/DELETE
/stocks/commandes/{id}/lignes[/{ligne}]`), une commande envoyée est
figée. Les noms des cliniques viennent de l'annuaire `GET /cliniques`
(id, nom, boxes — tout employé, sans le domaine `organisation`).

### 2.5 Liaisons par nom

L'API référence tout par clé étrangère ; le nom n'est qu'une
dénormalisation d'affichage. Inventaire des points front à convertir :

- **RDV → patient** : `patientName`/`ownerName` deviennent
  `patient_id` (+ nom dénormalisé servi). Disparaissent :
  `patients.byName`, le recalage de `ensureDay`, la résolution
  fragile de `finconsult.open` (`name + ownerName`, qui, en cas
  d'échec, faisait silencieusement disparaître ordonnance et sortie
  de stock), la liste `DOGS_TODAY` du seed.
  Dans l'autre sens, la fiche et la liste patients servent
  `prochain_rendez_vous` (le plus proche à venir, toutes cliniques de
  l'organisation, hors annulés/no-shows/finis) — plus de recherche du
  prochain RDV côté front. La liste sert aussi `dernier_poids_kg`,
  `courbe_poids` (douze dernières pesées, sparkline) et
  `meta.facettes` (compteurs des filtres sur la recherche courante) ;
  la liste clients sert `noms_patients`.
- **No-shows** : le compteur indexé par nom de propriétaire devient
  un dérivé servi par client (homonymes enfin distingués).
- **Carnet/puce/AMICUS** (`records.js`) : la clé composite
  `nom|propriétaire` qui indexe trois maps (renommer l'animal = perdre
  le carnet) est remplacée par les ressources du patient (`patient_id`).
- **Factures et devis** : `pat`/`ow` par nom → `patient_id` ; lien
  devis → factures par numéro texte → par id.
- **Hospitalisation → vétérinaire** et **bloc → chirurgien/salle** :
  par nom aujourd'hui — à convertir en `employe_id`/`salle_id` quand
  ces domaines auront une API.
- **Boutique** : commandes en ligne à lignes structurées
  (`produit_id`) et client identifié ; fin des produits fantômes des
  stats.
- **Stupéfiants** : le patient d'un mouvement de sortie doit devenir
  une FK (exigence de traçabilité) — domaine API à créer.

### 2.6 Sortie de stock à la remise d'une commande boutique

Côté API, `POST /boutique/commandes/{id}/retirer` décrémente le stock
en transaction (un mouvement par ligne, via `GestionnaireStock`) — au
front actuel, `markDone` ne touche pas au stock du tout. Côté Vue :

- `markDone` appelle `retirer` puis **rafraîchit les stocks servis** ;
  aucune logique de décrément ne reste au client. Une rupture au
  moment du retrait revient en 422 (la commande en ligne ne réserve
  pas le stock) : l'UI doit présenter ce refus, cas qui n'existait pas.
- Généralisation : *tous* les décréments locaux disparaissent
  (encaissement, payer-plus-tard, fin de consultation, signature
  d'ordonnance, vente comptoir, soins d'hospitalisation à terme),
  ainsi que le drapeau `stocked` — l'idempotence est garantie par le
  serveur. Après chaque geste à effet de stock, le front recharge la
  ventilation (ou lit celle renvoyée par la réponse).
- Le seuil d'alerte s'évalue côté serveur sur le stock **total de
  l'organisation** ; les toasts « stock bas » du front doivent lire
  cette information servie plutôt que comparer `c1` seul.

### 2.7 Autres divergences à signaler (constatées pendant l'inventaire)

- **Catalogue de prestations** : `ACTES` (caisse) et `FC_CATALOG`
  (fin de consultation) divergent sur les prix (vaccination 48/42,
  chirurgie 420/380, radio 120/95, euthanasie 180/160). L'API tranche
  avec `GET /facturation/prestations` : les deux écrans doivent
  consommer ce catalogue et leurs constantes locales disparaître.
- **TVA** : la caisse l'extrait du TTC, la boutique l'ajoute en sus —
  le serveur fait foi, le front n'affiche que des montants servis.
- **Absences en offsets relatifs** (`d0: -32`) : l'API sert des dates
  absolues ; les helpers front deviennent de purs formateurs.
- **Vente comptoir hors caisse** : au front, `checkout` n'entre ni
  dans la clôture Z ni dans la TVA. `POST /boutique/vente` réconcilie
  côté serveur ; vérifier à la bascule ce que la clôture de caisse
  servie inclut, et aligner l'écran.

---

## 3. Ordre de bascule

Principe : du store le moins dépendant au plus dépendant, chaque étape
livrant les référentiels dont la suivante a besoin. Les stores restent
l'interface des composants (le front est en lecture seule pour ce
dépôt : l'ordre ci-dessous est une recommandation pour l'équipe front).

### Étape 0 — Socle (préalable à tout)

- **Couche HTTP** : un client unique sur `/api/v1` qui déballe
  l'enveloppe `data` des Resources, gère la pagination
  (`per_page`, max 100 — les stores actuels tiennent tout en mémoire,
  la liste des ~70 patients était générée localement), et sur lequel
  chaque domaine pose son module `src/api/<domaine>.js` à la manière
  de la frontière `src/api/auth.js` existante.
- **Jeton** : stockage du jeton Sanctum, en-tête `Authorization`,
  rafraîchissement avant l'expiration 24 h (`POST /auth/rafraichir`),
  restauration de session au boot par `GET /auth/moi`, purge et
  retour au login sur 401. Le jeton porte la clinique : le changement
  de clinique est une ré-émission de jeton, pas un paramètre de
  requête.
- **Gestion des erreurs** : format Laravel `{ message, errors? }` —
  les 422 mappés champ par champ dans les formulaires (les stores
  n'ont aujourd'hui aucune notion d'échec de validation serveur), les
  403 (permissions par domaine) et 409/410 éventuels vers le toast ;
  états de chargement (les stores sont 100 % synchrones aujourd'hui).

### Étapes 1 → 8

| # | Store(s) | Doit exister avant |
|---|---|---|
| 1 | `auth` | Le socle. Remplacer le corps des fonctions de `src/api/auth.js` (frontière prévue pour ça), ajouter moi/rafraichir/déconnexion. |
| 2 | `org` | Étape 1 (jeton). Fournit le référentiel cliniques/sociétés — remplace aussi la constante `CLINICS` que `hosp.js`, `stocks.js` et `shop.js` importent du store d'hospitalisation. |
| 3 | `equipe` + `settings` (horaires) | Étapes 1–2 (cliniques, auteur des gestes). Employés unifiés (§2.1), absences en dates absolues, gardes servies, horaires par clinique (§2.4). Sans ça, le planning n'a ni lignes fiables ni grille. |
| 4 | `patients` + `owners` (+ volets patient de `records`/`tools` : carnet, puce, AMICUS, dents, relevés, pesées) | Le socle (pagination/recherche serveur). C'est la bascule des identifiants : tout ce qui suit référence `patient_id`/`client_id`. Supprimer les deux seeds procéduraux et les lookups par nom (§2.5). |
| 5 | `products` + `stocks` | Étapes 2–3 (cliniques, jeton-clinique pour les écritures). Produits, mouvements servis (fin de l'historique fictif), ajustements, commandes fournisseur, lots. Prérequis de toute sortie de stock servie. |
| 6 | `invoices` + `devis` + `ordos` | Étapes 4–5 (FK patient, produits pour les lignes `med`) et 3 (auteur, lane). Adopter `GET /facturation/prestations` (fin d'`ACTES`/`FC_CATALOG`), numéros servis, encaissement/clôtures/décomptes, cycle devis, registre d'ordonnances avec sorties de stock serveur. |
| 7 | `shop` | Étapes 4–5 (clients identifiés, produits). Commandes structurées, retrait avec sortie de stock (§2.6), vente comptoir réconciliée avec la caisse, vitrine, statistiques servies. |
| 8 | `planning` + `records` (CR) + `finconsult` (+ effets de `tele`) | Tout le reste : employés/lignes/salles (3), horaires (3), patients (4), factures/prestations/ordonnances (6). RDV avec FK, statuts à effets serveur (« fini » → facture), fin de consultation transactionnelle (le rollback par snapshots disparaît), disponibilités et prochain créneau servis, urgences/demandes/liste d'attente, audits servis, undo par fenêtres serveur (§2.3). `finconsult` est volontairement dernier : il touche sept stores. |

**Hors plan de bascule** : `bloc`, `hosp`, `imaging`, `labs`, `tele`
(session visio), certificats et registre des stupéfiants — leurs
domaines API n'existent pas (§1.8). Ils restent sur seeds ; l'ordre de
construction API suggéré par le risque : stupéfiants (légal), puis
hospitalisation (stock + facturation déjà servis), labo/imagerie,
bloc, visio. `theme`, `toast`, `prefs` et les préférences d'interface
de `settings` ne basculent jamais.
