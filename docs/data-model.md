# Modèle de données — Planning Vétérinaire

Document de référence pour les migrations Laravel de l'API.

**Migrations (20.10.2026)** : remises à zéro avant toute mise en
production, une migration par table, chacune dans son état final (les
trois fichiers de base de Laravel gardent leurs tables techniques
regroupées). Jusqu'à la première mise en production, un changement de
schéma se fait **dans la migration de la table**, pas dans une nouvelle
migration, puis `migrate:fresh --seed` en local. Après la mise en
production, on revient aux migrations successives habituelles.

Deux sources, dans cet ordre d'autorité :

1. **Les stores implémentés** (`src/stores/`) — planning, patients,
   propriétaires, factures/caisse, devis, hospitalisation, laboratoire,
   imagerie, bloc, ordonnances, dossier médical (CR, carnet, relevés),
   produits, réglages, rappels, outils, **équipe, organisation/admin,
   boutique, stocks, authentification** (portés depuis la première
   version de ce document — relu et intégré ci-dessous). Là où
   l'implémentation a tranché, c'est elle qui fait foi.
2. **Le prototype** (`design_handoff_veterinary_app/Planning Veterinaire.dc.html`)
   — pour le seul écran non porté restant : l'espace client (portail
   propriétaire).

Les noms de champs du front (camelCase) sont indiqués tels quels, avec
une suggestion de type SQL. Convention front → base : `startsAt` →
`starts_at`, etc.

Remarque : dans la démo, patients et propriétaires sont générés
procéduralement (RNG seedé) et les **RDV, factures et devis référencent
le patient par nom** (champ dénormalisé). Les écrans plus récents
(hospitalisation, laboratoire, imagerie, bloc, ordonnances) référencent
déjà un `patientId`. En production, tout passe par de vraies clés
étrangères ; le nom affiché reste une dénormalisation de confort.

---

## Vue d'ensemble des relations

```
Organisation 1──N Société 1──N Clinique     Client 1──N Patient
Clinique 1──N Praticien (staff)             Client 0..1── Compte espace client
Praticien 0..1── Ligne de planning          Patient 1──N Vaccination / Visite / Pesée
Ligne de planning 1──N Rendez-vous                    / Document / Imagerie / Relevé oph
Praticien 1──N Absence                                / Relevé dentaire / Analyse labo
Rendez-vous N──1 Patient                              / Examen imagerie / Ordonnance
Rendez-vous 0..1── Salle de consultation    Rendez-vous 0..1── Facture (apptId)
Rendez-vous 1──N Entrée d'audit             Rendez-vous 0..1── Compte rendu SOAP
Rendez-vous 0..1── Téléconsultation         Facture 1──N Ligne de facture
Devis N──1 Patient · 0..2── Factures        Facture 0..1── Décompte assurance
Hospitalisation N──1 Patient · N──1 Clinique · 1──N Soin / Relevé de constantes
Intervention bloc N──1 Patient · N──1 Salle de bloc · 1──N Point de checklist
Ordonnance N──1 Patient · 1──N Ligne (→ Produit)
Analyse labo N──1 Patient · 1──N Résultat de paramètre
Clôture de caisse (Z) 1──N Facture couverte
Produit 1──N Stock par clinique · 0..N Mouvement de stupéfiant
```

La « ligne » du planning (`lane`) est soit un **praticien**
(consultation), soit une **ressource** (bloc opératoire, salle
d'imagerie). Pour l'API : `rendez_vous.praticien_id` nullable **ou**
`rendez_vous.salle_id` nullable, la ligne affichée étant l'un ou l'autre.

---

## 1. Praticien / Équipe

### Porté (store `planning.staff`, consommé par l'écran Équipe)

L'écran Équipe est implémenté (`equipe.js`) et lit le `staff` du
planning, désormais complet avec les champs RH du proto :

| Champ front | Type suggéré | Description |
|---|---|---|
| `id` | PK | `st1`… |
| `name` | string | « Dr Anna Meyer » |
| `initials` | string(3) | Avatar (« AM ») |
| `role` | string | « Vétérinaire », « ASV », « ASV · Accueil », « ASV · Bloc » |
| `laneId` | FK nullable → ligne planning | Seuls les vétérinaires ont une colonne ; ASV = `null` |
| `clin` | FK → clinique | Clinique de rattachement |
| `taux` | tinyint | % d'activité (ETP dérivé : Σ taux / 100) |
| `contrat` | string | « CDI » / « CDD → 12.2026 » |
| `entree` | mois | Mois d'entrée (« 03.2019 ») |
| `tel`, `mail` | string | Contact |
| `droit`, `taken` | tinyint | Jours de congé annuels / déjà pris ; le solde affiché déduit aussi les congés validés à venir (dérivé des absences) |

**Divergence à réconcilier en base** : l'écran Organisation (section
2 bis) porte **une seconde représentation d'équipe** (`org.clinics[].team`)
avec d'autres personnes (« Dr S. Blanc », « Dr K. Ito », « L. Perrin »…)
et d'autres champs (accès `ok`/`sus`, contrat `signe`/`attente`/`pdf`,
licenciement). Les deux listes ne sont pas réconciliées au front. En
base : **une seule table `employes`**, dont l'écran Organisation et le
planning sont deux vues.

**Une fiche par personne et par organisation (21.10.2026, décision de
Jim)** : contrat, salaire, congés, rôle et accès sont ceux de la
personne, quel que soit le nombre de ses cliniques. Les cliniques où
elle travaille sont ses **affectations** (`clinique_employe`), choisies
par l'administrateur à l'embauche (toutes, ou quelques-unes) puis sur la
fiche ; rien n'est automatique. On ne voit et n'agit que dans les
cliniques où l'on est affecté : le scope `clinique` des employés suit
les affectations, et un jeton n'ouvre que la clinique d'une affectation
(un « rattachement » = la fiche × une de ses cliniques). Une absence est
celle de la personne : validée, elle vide ses colonnes de planning dans
chacune de ses cliniques. La colonne d'un employé retiré d'une clinique
reste (son historique y est accroché) mais ne s'affiche plus et n'offre
plus de créneaux. L'embauche lie le compte Veterinary de la personne par
son **code employé** (`users.code_employe`, affiché « K7F-3QZ »).

### Ligne de planning (`lanes`)

| Champ | Type | Description |
|---|---|---|
| `id` | PK | `mey`, `sol`, `adj`, `bl1`, `img` |
| `name` | string | « Dr Meyer », « Bloc 1 », « Radio / Écho » |
| `sub` | string | « CONSULTATION », « CONSULT · URGENCES », « CHIRURGIE », « IMAGERIE » |
| `role` | enum | `consult` \| `bloc` \| `img` — conditionne pause déjeuner et champs éditables |
| `initials` | string(2) | Initiales / code affiché |
| `group` | enum | **Consultation**, **Chirurgie**, **Imagerie** (groupes repliables) |

Le mode « cabinet solo » (Réglages > Structure) filtre l'affichage à
Dr Meyer + Bloc — c'est un filtre de vue, pas une donnée.

### Absences (`absences`) — bloquent la colonne du praticien

| Champ front | Type | Description |
|---|---|---|
| `id` | PK | `ab1`… |
| `staffId` | FK → praticien | |
| `type` | enum | `conge` \| `maladie` \| `formation` \| `recup` \| `garde` |
| `d0`, `d1` | date | Premier / dernier jour. **Les seeds stockent des offsets relatifs à aujourd'hui — en base, dates absolues** |
| `status` | enum | `ok` (validée) \| `att` (en attente) \| `ref` (refusée) — **`ref` ajouté par l'écran Équipe**, absent de la première version de ce document |
| `note` | text | Motif libre |
| `by` | string → FK user | Qui a validé / saisi — texte libre au front (« Validée par Dr Meyer »), FK utilisateur en base |

Cycle implémenté (écran Équipe) : demande `att` → **validation**
(passe `ok` et vide la colonne du praticien sur la période : RDV passés
en `annule`, audités avec motif « Absence praticien — … ») ou **refus**
(`ref`, notification). Saisie directe admin (créée directement `ok`,
mêmes annulations), **modification** (période/type/note) et
**suppression** d'une absence sont permises — la suppression rouvre la
colonne (à ajouter aux « suppressions tolérées »). Les RDV annulés ne
sont pas restaurés par la suppression de l'absence.

**Gardes de week-end** : la rotation affichée (6 week-ends à venir,
échanges possibles) est **dérivée** (numéro de semaine modulo l'effectif
vétérinaire + échanges mémorisés). En base, seule l'absence de type
`garde` est une donnée ; si l'échange de rotation doit survivre à la
session, prévoir une petite table `gardes_weekend` (date du samedi,
employé) — sinon état d'UI.

Codes couleur (`ABSENCE_TYPES`) : congés bleu `#5aa7f5`, maladie rouge
(`--urgent`), formation violet `#a78bfa`, récupération ambre (`--warn`),
garde vert `#22a06b`. Une absence **validée** couvre la colonne de
l'ouverture à la fermeture (zone hachurée « TYPE — Nom · RETOUR date »)
et interdit la création de RDV sur la ligne. La `garde` ne bloque pas :
badge « DE GARDE » sur la ligne.

---

## 2. Salle et Clinique

Trois notions de « salle » coexistent — confirmées par l'implémentation :

1. **Lignes-ressources du planning** : `Bloc 1` (role `bloc`),
   `Radio / Écho` (role `img`) — portent des RDV comme un praticien.
2. **Salles de consultation** (champ `room` d'un RDV sur une ligne
   consult) : « Consultation 1 », « Consultation 2 » (défaut UI),
   « Consultation 3 », « Salle de soins ». Stocké `null` tant qu'aucun
   choix explicite (le défaut est résolu à l'affichage).
3. **Salles de bloc opératoire** (`bloc.salles`) : « Bloc 1 »,
   « Bloc 2 » — liste gérable (ajout / renommage / suppression dans
   l'UI, suppression refusée si des interventions non terminées y sont
   rattachées ; le renommage se répercute sur les interventions).

Suggestion API : une table `salles` unique — `id`, `nom`, `type`
(`consultation` \| `bloc` \| `imagerie`), `clinique_id`, avec un flag
« affichée comme ligne du planning ».

### Clinique

La hiérarchie complète est implémentée (store `org`) : sociétés
(`id`, `n`, `ide` n° IDE « CHE-… », créables), cliniques (création par
assistant en 3 étapes, modification, suppression — refusée s'il ne
resterait aucune clinique), rattachement clinique → société modifiable.
`hosp.js` garde en plus la constante `CLINICS` + `BOX_COUNT` (8 / 6),
non branchée sur `org` — même donnée en double, à unifier en base.

| Champ | Type | Description |
|---|---|---|
| `id` | PK | `c1`, `c2` |
| `n` / `legal` | string | Nom court / raison sociale |
| `soc` | FK → société | Société juridique |
| `logo` | fichier nullable | Logo de la clinique |
| `street`, `npa`, `city` | string | Adresse suisse (NPA 4 chiffres) |
| `gps` | lat/lng decimal | Position carte |
| `tel` | string | « +41 21 … » |
| `hSem`, `hSam`, `hDim` | horaires | Voir divergence ci-dessous |
| `boxCount` | tinyint | Capacité d'hospitalisation (box) — dans `hosp.js`, pas dans `org` |
| `pats`, `em`, `sm` | compteurs | Ventilation d'usage des quotas SaaS |

**Divergence horaires — toujours ouverte, et désormais doublée** : le
planning lit un seul jeu d'horaires (store `settings` global, minutes
typées), tandis que l'écran Organisation affiche et édite des horaires
**par clinique**… en chaînes libres (`hSem` « 08:00 – 18:30 », `hSam`,
`hDim` « Fermé »), jamais consommées par le planning. En base : une
seule source, `horaires_ouverture` **par clinique** et typée (heures +
pause + jours fermés) ; les chaînes de l'écran Organisation en sont un
rendu.

### Équipe par clinique, embauche, licenciement (écran Organisation)

`org.clinics[].team` : `{ n, role, acc: ok|sus (accès suspendu),
ct: signe|attente|pdf (état du contrat), lic?: { fin } (licenciement
notifié) }`. Gestes implémentés : suspension/rétablissement d'accès
(plafonné par la formule), **embauche** (compte vérifié par QR ou code,
contrat PDF déposé ou généré + signatures, poste/taux/salaire/date,
portée une clinique ou toutes), **licenciement** (formulaire complet :
type, motif, dates, libération, soldes vacances/heures sup/13e,
documents de sortie, kit matériel — aboutit à `lic.fin` + PDF « archivé
au dossier RH »). En base, ces états vivent sur `employes` (cf.
divergence section 1) : `acces_suspendu`, `contrat_statut`,
`licencie_le` / dossier RH.

### Comptes du personnel, embauche et invitation (21.10.2026)

Chaque personne qui travaille dans l'application a son compte, sans exception : `employes.user_id` est obligatoire, et un compte ne se supprime pas tant qu'une fiche y est rattachée. Seuls les **clients** des cliniques peuvent exister sans compte (`clients.user_id` facultatif). Deux chemins mènent à une fiche, tous deux par le service `Embauche` (doublon, place dans la formule, rôle, cliniques) :

- **Code employé** : la personne donne le code de son compte (`POST /equipe/employes`, vérifié d'abord par `GET /equipe/comptes/{code}`).
- **Invitation par e-mail** : `invitations_employe` (organisation, `email` en minuscules, `nom`, `donnees` de la fiche à créer, empreinte SHA-256 du jeton du lien, `invitee_par`, `expire_le` à 14 jours, `acceptee_le`/`employe_id`, `annulee_le`). `POST /equipe/invitations` envoie l'e-mail (une nouvelle invitation à la même adresse remplace la précédente), `GET` liste celles en attente, `…/renvoyer` change le lien, `DELETE` annule. La personne ouvre `/invitation/{jeton}`, se connecte avec l'adresse invitée (son compte naît alors s'il n'existe pas), et `POST /invitations/{jeton}/accepter` crée sa fiche ; seule l'adresse invitée peut accepter. Rien n'est créé avant.

### Services, animaux soignés, spécialités (21.10.2026)

Ce qu'une clinique propose, coché par l'administrateur (écran Organisation, `PUT /organisation/cliniques/{id}/services`, domaine `organisation`, tout l'état d'un coup) :

- **`clinique_services`** (`clinique_id`, `service`, `modalite`) : un service par ligne, enum `ServiceClinique` (`hospitalisation`, `chirurgie`, `imagerie`, `laboratoire`, `boutique`, `teleconsultation`, `urgences`, `domicile`). Les urgences précisent `modalite` (`ModaliteUrgences` : `permanence` 24 h sur 24, `horaires` aux heures d'ouverture). L'hospitalisation exige `cliniques.nombre_boxes` (1 à 50) ; la couper est refusé tant qu'un séjour est en cours, et réduire les boxes sous un box occupé aussi.
- **`clinique_especes`** (`clinique_id`, `espece`) : animaux soignés, mêmes codes que les patients (`Espece`).
- **`cliniques.affiche_veterinaires`** (vrai par défaut) : l'annuaire public montre la liste des vétérinaires et de leurs spécialités ; sinon les icônes seules.
- **`specialites_veterinaire`** (`user_id`, `specialite`) : spécialités d'un vétérinaire (enum `Specialite`, avec mots-clés de recherche : « hanche » mène à l'orthopédie), sur **son compte** : il les déclare lui-même avec son GLN (`GET`/`PUT /auth/profil-veterinaire`, Réglages › Profil), et elles le suivent dans toutes ses cliniques et organisations. Le GLN est cherché dans MedReg au nom du compte (`users.gln_verifie_le` : trouvé au registre) ; un GLN d'un autre compte est refusé. Celles d'une clinique se déduisent de ses vétérinaires affectés.

**Effet dans l'espace vétérinaire :** un module n'est ouvert que si la clinique du jeton a le service (middleware `service:`), en plus de la permission du rôle : hospitalisation, laboratoire, imagerie, bloc (`chirurgie`), téléconsultation, boutique. La session (`clinique.services`) les liste pour les menus. Côté public, la vitrine et les commandes de la boutique ne montrent que les cliniques qui ont `boutique`, et une demande en visio exige `teleconsultation`. Une clinique créée n'a aucun service.

### Inscription d'une clinique, vérification MedReg, accès (20.10.2026)

Un vétérinaire inscrit son organisation depuis son compte (connecté,
avec ou sans clinique) : `POST /inscription-clinique/gln` dit pendant
la saisie ce que MedReg sait de son GLN, `POST /inscription-clinique`
crée l'organisation (nom de la clinique), sa société (IDE facultatif),
sa première clinique (horaires de départ) et le vétérinaire
administrateur (« Dr Prénom Nom »). La réponse est une session neuve
sur la nouvelle clinique ; l'ancien jeton est révoqué. Une seule
inscription à la fois : une clinique qui attend sa vérification ou sa
première formule se termine d'abord.

- **MedReg** (`App\Services\Inscription\MedReg`) : les appels publics
  du site de l'OFSP (`person/search` par GLN et profession 4, puis
  `person` par id), pas une interface officielle. Le GLN est contrôlé
  avant (13 chiffres, clé de GS1).
- **Vérifiée aussitôt** si le GLN est celui d'un vétérinaire au même nom
  (accents, casse, second prénom et titre ignorés) et d'aucun autre
  compte : `users.gln`, `medreg_id`, `gln_verifie_le`.
- **Sinon, à la main** (`organisations.verification_raison` :
  `introuvable`, `nom_different`, `gln_deja_utilise`,
  `medreg_indisponible`) : e-mail à `PLATEFORME_VERIFICATION_EMAIL` avec
  deux liens signés (30 jours) vers une page de l'API qui confirme, puis
  décide en POST (`/verification-cliniques/{id}/valider|refuser`, motif
  facultatif envoyé au vétérinaire). Le vétérinaire reçoit un e-mail
  dans les deux cas ; il peut répondre au refus. Une panne de MedReg est
  retentée toutes les heures pendant une semaine (`inscriptions:reessayer`).
- **Accès** : `organisations.verification` (`en_attente`, `verifiee`,
  `refusee`). Toute l'application clinique exige une organisation
  vérifiée **et** payée (période en cours, y compris pendant les
  relances d'un prélèvement refusé) : middleware `organisation.ouverte`,
  403 avec `code` `verification_en_attente`, `verification_refusee` ou
  `abonnement_requis`. Seules la fiche de l'organisation, la formule,
  la carte et les factures restent ouvertes, pour payer ; aucune formule
  ne se paie avant la vérification. La session (`/auth/moi`) porte
  `organisation { verification, verification_note, abonnement_en_cours,
  fermeture }`. Une organisation fermée disparaît de l'annuaire, des
  boutiques, des demandes de rendez-vous et de son site public.

### Catalogue d'actes : prestations et tarifs (20.10.2026)

Les administrateurs gèrent le catalogue (`POST`, `PATCH`, `DELETE
organisation/prestations`, lecture par `facturation/prestations`) : un
acte, un prix. Code généré depuis le nom pour un acte ajouté. Les actes de
départ (`PrestationsParDefaut`, `systeme: true`) sont lus par leur code
(types de rendez-vous, fin de consultation, séjours) : ils se renomment et
changent de prix, ils ne se suppriment pas. Les lignes de facture et de
devis recopient nom et prix : supprimer un acte ne touche aucune facture.

### Premiers pas d'une clinique neuve (20.10.2026)

Une clinique inscrite part vide. `GET organisation/premiers-pas`
(administrateurs) donne l'état de huit étapes pour la clinique du jeton :
praticiens au planning, salles, horaires, prestations, équipe, position
sur la carte,
rappels, caisse, site. Chacune se déduit des données (une ligne de
planning d'employé, une salle, des horaires modifiés, plus d'un employé,
une latitude, un terminal, un site publié) ; les horaires, l'équipe
(« je travaille seul »), les rappels, la caisse et le site se confirment
aussi à la main (`PUT … {confirmer}`), la liste se masque
(`{masque}`). État dans `organisations.premiers_pas` (json). Le front
l'affiche en tête de « Ma journée » ; praticiens et salles s'ajoutent dans
Réglages › Structure (le bloc et l'imagerie y reçoivent aussi leur ligne).

### Formule SaaS, quotas, facturation d'abonnement (niveau organisation)

Depuis le 20.10.2026, **abonnements maison** : l'application tient les
abonnements et émet ses factures (service
`App\Services\Abonnements\AbonnementSaas`) ; Stripe ne fait plus que garder
la carte et la prélever (passerelle `PasserelleAbonnement`, PaymentIntent
confirmé sur la carte enregistrée ; double `PasserelleAbonnementFactice` en
test). Plus de Stripe Billing (0,7 % du volume économisé). **Aucune TVA** :
Meteora Systems Sàrl n'y est pas assujettie (art. 10 LTVA) ; l'émetteur est
dans `config/plateforme.php`.

- `plan` (`individuel` \| `cabinet` \| `hopital`, enum `FormuleSaas` :
  prix mensuel et limites cliniques, employés actifs, e-mails, SMS,
  patients, stockage), `cycle` = `mois` (seul cycle proposé).
- **Carte** : `POST organisation/moyen-paiement/intention` crée le client
  Stripe au besoin (`stripe_client_id`) et un SetupIntent hors session ;
  le navigateur confirme la carte dans le Payment Element de Stripe (la
  carte ne passe jamais par nos serveurs) puis `PUT
  organisation/moyen-paiement {payment_method}` la vérifie (même client),
  en fait la carte des prélèvements et garde son libellé masqué
  (`moyen_paiement` « Visa •••• 4242 · exp. 08/28 »,
  `stripe_moyen_paiement_id`) ; l'ancienne est détachée.
- **Abonnement** (comme Netflix ou Claude) : payé d'avance, renouvelé à la
  **date anniversaire** (`ancre_jour` ; le dernier jour d'un mois plus
  court), au même prix quelle que soit la longueur du mois.
  `POST organisation/abonnement {plan}` : première souscription (prélevée
  aussitôt) ; formule **plus chère** : nouveau cycle aujourd'hui, prix
  plein moins le crédit du temps non utilisé (prorata au jour) ; formule
  **moins chère** : `formule_suivante`, appliquée au renouvellement, sans
  remboursement ; même formule : annule une résiliation ou un changement
  programmé. `statut_abonnement` : `actif` \| `impaye` \| `resilie` (null :
  aucun abonnement payant), `periode_debut`, `periode_fin` (prochain
  renouvellement, ou fin d'accès si résilié), `resilie_le`.
- **Renouvellements** : `abonnements:renouveler` (toutes les heures).
  Prélèvement hors session le jour de l'échéance ; refusé : `impaye`,
  l'accès reste ouvert et le prélèvement est retenté une fois par jour
  pendant 3 jours (`tentatives`, `prochaine_tentative_le`), avec un e-mail
  aux administrateurs à chaque refus (`PaiementAbonnementRefuse`, date de
  fermeture : `fermeture_le`). Payé entre-temps, la période commence quand
  même à l'échéance. La dernière tentative refusée ferme l'accès
  (`resilie`) : la facture de la période qui ne commence pas est annulée,
  les minutes de transcription déjà consommées restent dues sur leur propre
  facture, à régler avant toute nouvelle formule ; une reprise ouvre une
  nouvelle période le jour du paiement.
- **Cartes** : `carte_expire_le` (fin du mois d'expiration). Une carte qui
  expire avant le prochain prélèvement est signalée 14 jours avant puis la
  veille (`CarteExpireAvantPrelevement`, `carte_alertee_le`). Une carte
  remplacée par la banque est mise à jour par Stripe (webhook
  `payment_method.automatically_updated`, à abonner aussi en production).
- **Confirmation bancaire** : quand la banque la demande, la réponse porte
  `paiement {facture_id, client_secret, cle_publique}` ; la page confirme
  avec Stripe.js puis `POST organisation/factures/{id}/confirmer`. Le
  webhook `payment_intent.succeeded` (métadonnées `plateforme`, `facture`)
  applique aussi la facture. Une facture en attente se règle par
  `POST organisation/factures/{id}/regler`.
- **Packs** (`TypePack`) : facture prélevée aussitôt ; la quantité s'ajoute
  aux `quota_extra_*` au paiement (`quota_applique`, une seule fois).
- **Factures** (`organisation_factures`, `proprietaire_factures`) : les
  nôtres, numérotées `MS-AAAA-NNNNN` (`sequence_factures_plateforme`, une
  suite pour toute la plateforme), `lignes` (libellé, montant ; un crédit
  est négatif), période, `statut` `payee` \| `a_payer` \| `annulee`,
  `paye_le`, `carte`, `stripe_paiement_id`. `GET organisation/factures/{id}`
  sert l'émetteur et le client pour imprimer la facture (A4, dans le
  navigateur). Les factures Stripe d'avant le 20.10.2026 gardent leur
  `pdf_url` ; les anciennes « F- » simulées ne sont pas servies.
- Changement de formule bloqué si les limites cibles sont dépassées
  (`depassementsPour`).

**Offre des propriétaires** (16.10.2026) : tout l'espace client est
gratuit (les vétérinaires financent la plateforme). « Plus », **12 CHF par
an** (depuis le 20.10.2026, payé d'avance, renouvelé à la date
anniversaire), ne donne que d'autres planètes au jeu de l'accueil de
l'espace client. Même service (`AbonnementSaas`), abonné = compte `users`
(`formule` gratuit \| plus, mêmes colonnes que l'organisation), factures
dans `proprietaire_factures`. Routes
`/espace/abonnement` (GET), `…/carte/intention`, `PUT|DELETE …/carte`,
`POST …/plus`, `…/resilier` (retour à Gratuit en fin de période),
`…/reactiver`, `GET …/factures/{id}` (à imprimer), `POST
…/factures/{id}/regler|confirmer`. Les cliniques s'abonnent au mois.

**Jeu de la planète** (16.10.2026) : `patients.decede_le` (déclaré par
le propriétaire pour tout animal du compte, `POST|DELETE
/espace/animaux/{id}/deces`, ou par la clinique ; coupe les rappels) met
une tombe à son nom sur la planète ; boutique d'objets (`ArticlePlanete` :
balle, gamelle, lanterne, banc, niche, cerisier, de 0,50 à 2 CHF, sans
TVA), achetés une fois (`achats_planete`, unique par compte), prélevés sur
la carte du compte (notre facture, `GET /espace/planete`, `POST
/espace/planete/achats`, `paiement` si la banque demande une
confirmation).

**Achats dans l'app iPhone** (17.10.2026) : Apple exige son paiement
(StoreKit 2) pour Plus et les objets de la planète vendus dans l'app.
Prix = celui du site majoré de 30 % (`AchatsAppStore::MAJORATION`, fixé
dans App Store Connect ; l'app affiche le prix de l'App Store). Produits
`ch.veterinary.client.plus` (abonnement annuel, 15,60 CHF) et
`ch.veterinary.client.planete.<objet>` (non consommables). Chaque compte
a un identifiant App Store (`users.apple_compte`, UUID posé comme
appAccountToken sur chaque achat). L'app envoie les transactions signées
(JWS) à `POST /espace/app-store/transactions`, vérifiées sur place contre
la racine d'Apple (`resources/apple/AppleRootCA-G3.pem`, chaîne,
marqueurs, signature ES256 : `VerificateurJws`) et réservées au compte
qui les a passées. Plus d'Apple vit à côté de celui de Stripe
(`apple_abonnement_id`, `apple_fin`, `apple_renouvelle`) : `GET
/espace/abonnement` sert `formule` (plus si l'un des deux est actif) et
`source` (`stripe` ou `app_store`) ; un Plus Stripe est refusé si celui
d'Apple court. Objet acheté : ligne `achats_planete` avec
`apple_transaction_id`. Notifications serveur V2 d'Apple sur `POST
/app-store/notifications` (renouvellement, résiliation, remboursement),
adresse à déclarer dans App Store Connect. En local, `APPLE_STOREKIT_XCODE`
accepte les achats de test signés par Xcode (fichier
`ios/App/App/Veterinary.storekit` du dépôt web).

Pas encore réel : les compteurs d'usage (`usage_emails`, `usage_sms`,
`usage_stockage_go`) ne sont alimentés par aucun envoi ni fichier (valeurs
seedées), et rien ne bascule l'organisation en lecture seule après la fin
d'un abonnement résilié.

### Multi-clinique — dimension confirmée du produit

La hiérarchie **Organisation → Sociétés → Cliniques** structure le proto
de bout en bout ; l'implémentation actuelle la consomme déjà :

- **Stocks** : stock **par clinique** sur chaque produit
  (`stock: { c1, c2 }`), décréments par clinique (hospitalisation
  décrémente la clinique du séjour, ordonnances et caisse `c1`).
- **Hospitalisation** : séjours filtrés par clinique (sélecteur UI),
  box par clinique.
- **Factures** : champ `clin` sur chaque facture — le CA par clinique
  de l'écran Caisse est calculé dessus.
- **Plan SaaS** (`PLANS`, porté — cf. section 2) : plafonne le nombre
  de cliniques et d'employés actifs.
- **Boutique** (portée) : caisse comptoir **par clinique** (panier borné
  au stock de la clinique), commandes en ligne rattachées à une
  clinique, ventes tracées avec `clin`.
- **Stocks** (portés) : ajustements, bons de commande fournisseurs
  (clinique de livraison `dest`) et lots de péremption par clinique.
- **Côté client** (non porté) : carte des cliniques, RDV pris auprès
  d'une clinique précise.

Tables portant `clinic_id` :

| Table | Rattachement |
|---|---|
| `employes` | **niveau organisation** ; cliniques par les affectations (`clinique_employe`) |
| `salles` (consultation, bloc, imagerie) | `clinic_id` direct |
| `lignes_planning` | via la salle ou le praticien ; le planning affiché est celui d'une clinique |
| `rendez_vous` | dérivable via la ligne, mais `clinic_id` direct recommandé |
| `factures`, `clotures_caisse` | `clinic_id` direct (champ `clin` déjà présent au front) |
| `hospitalisations` | `clinic_id` direct (champ `clin` au front) |
| `stocks` | pivot `produit × clinique` (quantité par clinique) |
| `horaires_ouverture` | par clinique (cf. divergence ci-dessus) |
| `absences` | via l'employé |
| `commandes_boutique`, `ventes` | `clinic_id` direct (implémenté : `orders.scope`, `sales.clin`) |
| `commandes_fournisseur`, `lots` | `clinic_id` de livraison / de détention (implémenté : `cmds.dest`, `lots.clin`) |
| `patients`, `clients` | **niveau organisation** (store unifié) ; le compteur `pats` par clinique est une ventilation d'usage, pas un rattachement exclusif |

---

## 3. Rendez-vous

| Champ front | Type suggéré | Description |
|---|---|---|
| `id` | PK | `a1`… ; générés : `n<timestamp>` |
| `laneId` | FK → ligne (praticien ou ressource) | Colonne du planning |
| `startsAt` | **datetime UTC** | Début (décision confirmée par l'implémentation — les minutes ne sont qu'un dérivé d'affichage) |
| `endsAt` | **datetime UTC** | Fin ; la durée est dérivée |
| `patientName` → `patient_id` | FK | Dénormalisé par nom dans la démo (vide pour un tampon) |
| `species` | enum espèce | Copie dénormalisée |
| `ownerName` → `client_id` | FK | Dénormalisé |
| `type` | enum | Type de RDV (voir ci-dessous) |
| `status` | enum | Statut (voir ci-dessous) |
| `flags` | array / pivot | Drapeaux (voir ci-dessous) |
| `reason` | string | Motif (« Boiterie postérieur droit ») |
| `arrivedAt` | datetime UTC nullable | Renseignée au passage en statut `salle` |
| `room` | FK nullable → salle | `null` = défaut UI « Consultation 2 » ; lignes bloc/img : imposée par la ligne |

**Stockage en `starts_at` / `ends_at` datetime UTC** — décision
entérinée et implémentée (le store convertit en minutes à l'affichage).
Raisons : séjours et actes traversant minuit ; changement d'heure
(l'UTC est monotone, conversion Europe/Zurich à l'affichage) ; requêtes
sur plage (`starts_at < Y AND ends_at > X`, indexable).

Champs affichés mais dérivés : origine (« Réservation en ligne » si flag
`neuf`, sinon « Accueil — téléphone »). Le règlement et l'assurance,
autrefois listés « à prévoir », vivent désormais sur la **facture**
(section 6).

### Types de RDV et libellés

| Code | Libellé UI | Durées vues en démo |
|---|---|---|
| `consult` | CONSULT | 20–45 min |
| `vaccin` | VACCIN | 20 min |
| `chir` | CHIRURGIE | 60–90 min (ligne Bloc) |
| `ctrl` | CONTRÔLE | 20 min |
| `imagerie` | IMAGERIE | 30 min (ligne Radio/Écho) |
| `urgence` | URGENCE | 20–45 min |
| `euth` | ACCOMPAGNEMENT | 45 min |
| `tele` | TÉLÉCONSULT | 20 min |
| `tampon` | — (créneau réservé) | 20–45 min |

Le `tampon` est un créneau sans patient : « RÉSERVÉ URGENCES » sur la
ligne urgences, « CRÉNEAU LIBRE » ailleurs. Non déplaçable, non
redimensionnable, jamais facturé ; cible de drop pour une urgence.

### Statuts et cycle de vie

Enum : `prevu` → `salle` → `cours` → `fini`, plus `noshow` et `annule`.

**Annulation — implémentée comme décidé** : jamais de suppression. Le
statut passe à `annule`, le motif est journalisé dans l'audit (champ
`motifAnnulation` de l'entrée d'audit), le RDV disparaît de la grille
(exclu de `dayAppointments`) et le créneau libéré est proposé à la
liste d'attente (`offerSlot`). Raccourcis clavier 1–4 =
prevu/salle/cours/fini sur le RDV sélectionné.

| Statut | Libellé | Rendu du bloc planning | Chip « Ma journée » |
|---|---|---|---|
| `prevu` | Prévu | fond verre (`--glass`), bord `--line-strong`, liseré `--text-3` | PRÉVU — neutre |
| `salle` | Arrivé | fond `--warn-soft`, bord `--warn-bd`, liseré `--warn` | EN SALLE — ambre |
| `cours` | En cours | fond `--glass-3`, bord `--accent-bd`, liseré `--accent` + point « live » | EN CONSULT. — accent |
| `fini` | Terminé | transparent, bord `--line`, opacité .62 | TERMINÉ — vert `#22a06b` |
| `noshow` | Non venu | hachures rouges, bord pointillé `--urgent-bd` | — |

Règles de transition implémentées :

- Bouton principal contextuel : Prévu → « Marquer arrivé » ; Arrivé →
  « Démarrer la consultation » ; En cours → « Terminer » ; Terminé →
  « Rouvrir » (retour `cours`).
- Passage à `salle` : mémorise `arrivedAt` (audité).
- **Passage à `fini` — trois chemins de facturation** :
  1. **Statut « Terminé »** (fiche RDV, raccourci 4) : crée une facture
     d'un acte dérivé du type (`TYPE_ACTE` → catalogue `ACTES`) et ouvre
     l'encaissement. Annulable 10 s (statut restauré + facture créée
     supprimée ; une facture rouverte est conservée). Si une facture
     liée au RDV existe déjà (`apptId`), elle est rouverte, pas
     dupliquée.
  2. **Fin de consultation** (« Ma journée », modale `fcO`) : actes
     cochés (catalogue propre, cf. décision ouverte « catalogue
     unique »), vaccin inscrit au carnet + rappel, médicaments prescrits
     → **ordonnance créée au registre** + sortie de stock + prescription
     jointe à la facture ; passe le RDV en `fini` sans repasser par le
     chemin 1. Annulable 10 s (tout est restauré : statut, facture,
     ordonnance, stock, carnet).
  3. **Fin de téléconsultation** : clôt le RDV via le chemin 1 puis
     ouvre le compte rendu SOAP.
- `noshow` : depuis `prevu` ou `salle` uniquement, hors tampon ;
  incrémente le **compteur de no-show du propriétaire** et propose le
  créneau à la liste d'attente. Au front le compteur est indexé par nom
  de propriétaire — en base : `clients.no_show_count` ou dérivé des RDV
  `noshow` du client.
- `annule` : statut terminal (voir ci-dessus).

### Audit des mutations de RDV — implémenté

Toute mutation passe par le store et produit ses entrées d'audit.
Forme confirmée :

Table `rendez_vous_audits` : `rendez_vous_id`, `user_id`, `batch_id`,
`champ`, `ancienne_valeur`, `nouvelle_valeur`, `created_at`.

- **`batch_id` groupe les champs d'un même geste** (un déplacement =
  `startsAt` + `laneId` dans le même lot) — l'historique de la fiche RDV
  s'affiche par geste, l'undo d'un toast annule un lot entier.
- Champs audités : `startsAt`, `endsAt`, `laneId`, `room`, `status`,
  `arrivedAt`, `patientName`, `species`, `ownerName`, `type`, `reason`,
  `motifAnnulation` (porté par l'entrée d'audit du passage à `annule`),
  plus une entrée « création » et une entrée « suppression » (seul cas :
  annulation d'une création dans la fenêtre d'undo du toast — en base,
  suppression physique tolérée dans cette fenêtre uniquement).
- L'acteur est l'utilisateur connecté (store `auth`) quand il existe,
  sinon « Équipe clinique (démo) » — la vraie session API le remplace.
  L'undo ne porte que sur le **dernier lot du même acteur** (un seul
  niveau, pas de pile — arbitrage multi-utilisateur repoussé).

### Surcharges visuelles par type

- `urgence` (non terminée) : liseré + bord + fond rouges (`--urgent*`).
- `euth` : bloc transparent à bord pointillé discret, texte `--text-2`.
- `tampon` : bord tireté, fond transparent, texte `--text-3`.
- Sélection : bord `--accent` + halo `--accent-soft`.

### Drapeaux (`flags`) — pastilles sur le bloc

| Code | Pastille | Libellé complet | Couleur |
|---|---|---|---|
| `ajeun` | AJ | À JEUN | ambre (`--warn`) |
| `mord` | !! | MANIPULATION PRUDENTE | rouge (`--urgent`) |
| `iso` | IS | ISOLEMENT | ambre (`--warn`) |
| `neuf` | N | NOUVEAU CLIENT | accent — vaut « réservation en ligne » |

### Contraintes de placement (drag & drop / clavier)

- **Échelle du jour (UI)** : timeline en minutes 0–1440, RDV borné à sa
  journée — contrainte de la vue jour, pas du stockage.
- **Aimant** : placement libre au pas de **1 min** (divergence assumée
  avec le proto qui arrondit à 5) ; aimant activable à
  **5 / 10 / 15 / 25 / 30 min** (défaut 15). Le resize suit le même pas.
- **Durée minimale : 10 min** (souris et clavier ⇧←/⇧→ pas de 5).
- **Création rapide** (clic droit) : RDV de **20 min**, type `consult`,
  statut `prevu`. Refusée sur une colonne bloquée par absence.
- **Placement d'une urgence / demande** : au prochain trou libre ;
  statut initial `salle` (urgence, `arrivedAt` = maintenant) ou `prevu`
  + flag `neuf` (demande en ligne).
- **Chevauchement autorisé — décision produit confirmée et
  implémentée** : jamais refusé, résolu uniquement à l'affichage par
  empilement en sous-lignes. **Aucune contrainte d'exclusivité en
  base** ; l'API accepte des intervalles qui se recouvrent.
- **Changement de ligne** : drag vertical (ou ↑/↓) libre ; le praticien
  est aussi éditable dans le panneau latéral (entre lignes `consult`).
- **Créneaux libres** : trous ≥ **15 min** entre RDV (tampons exclus),
  hors pause ; pour aujourd'hui, à partir de max(ouverture, maintenant).

### Horaires d'ouverture (réglages, valeurs par défaut)

- Semaine : **08:00 – 19:30** (`openM` 480, `closeM` 1170).
- Samedi : fermeture **15:00** (`satM` 900) ; dimanche **fermé** (option).
- Pause déjeuner : **12:30 – 14:00**, appliquée **uniquement aux lignes
  consultation** ; bloc et imagerie continuent.
- Zones « FERMÉ » hachurées hors horaires (placement pas physiquement
  bloqué).
- Réglages au pas de **15 min**, invariants : fermeture ≥ ouverture +
  2 h ; pause ≥ 30 min après l'ouverture et finissant ≥ 30 min avant la
  fermeture ; durée ≥ 15 min.
- Divergence corrigée au portage : samedi/dimanche suivent les réglages
  quel que soit le jour affiché (le proto forçait les horaires de
  semaine sur « aujourd'hui »).

### Files alimentant le planning (entités liées)

**Urgence en attente** : `id`, `patientName`, `species`, `ownerName`,
`reason`, `since` (heure d'arrivée), `triage` (**1–3**, 3 = plus
critique), `durationMin`. Se glisse sur le planning ou sur un tampon.

**Demande de RDV en ligne** : `id`, `patientName`, `species`,
`ownerName`, `reason`, `want` (souhait horaire libre), `mode` =
`valider` (à placer manuellement) \| `confirme` (créneau déjà choisi),
`durationMin`.

**Liste d'attente** : `id`, `patientName`, `species`, `ownerName`,
`tel`, `reason`, `pref` (préférence libre), `vet` (`any` ou id de
ligne), `durationMin` (20 / 30 / 45), `since`. Reçoit les offres de
créneau libéré (annulation, no-show) — l'offre elle-même
(`slotOffer`) est un état transitoire d'UI, pas une entité.

Une file « traitées à l'instant » (`traitees`) garde la trace des
urgences casées et demandes validées — journal de séance, dérivable des
RDV créés, pas une table.

---

## 4. Patient

| Champ front | Type suggéré | Description |
|---|---|---|
| `id` | PK | `p0`… |
| `name` | string | Nom de l'animal (clé de liaison de la démo — ne pas reproduire) |
| `species` | enum espèce | Codes ci-dessous |
| `breed` | string | Race |
| `sex` | enum | `Mâle` \| `Femelle` |
| `years` | tinyint | Âge (mieux : date de naissance en base) |
| `ownerIndex` / `ownerName` | FK → client | Propriétaire (dénormalisé) |
| `phone`, `mail` | string | Dénormalisés depuis le propriétaire |
| `alerts` | codes dérivés | `agressif` (!! rouge) · `allergies` (AL ambre) · `solde` (€ accent, dérivé de `debt`) |
| `debt` | decimal | Solde dû en CHF |
| `sterilized` | enum/bool | STÉRILISÉ(E) \| NON STÉRILISÉ |
| `chip` | string(15) | Puce format suisse AMICUS « 756 09X XXX XXX XXX » — modifiable depuis le carnet |
| `photo_chemin` | string, nullable | Photo de l'animal (16.10.2026) : fichier privé `photos-animaux/{organisation}/…` sur le disque local, servi par `GET photos-animaux/{fichier}` (public, cache un an, nom imprévisible) ; `POST|DELETE patients/{id}/photo` (image PNG, JPG ou WebP de 4 Mo au plus, droit `updateIdentity`) ; servi en `photo_url`. En production, le disque doit être persistant (bucket) |
| `proprietaire_id` | FK → users, nullable | Compte espace client qui gère la fiche (animal créé depuis l'espace, ou réclamé par puce et accepté par la clinique) |
| `acces_clinique` | enum `modification` \| `lecture` | Droits sur l'animal (16.10.2026) : quand un propriétaire gère la fiche, ce qu'il laisse à la clinique sur l'**identité** (nom, espèce, race, sexe, naissance, stérilisation, puce, AMICUS). `lecture` : 403 sur ces champs côté clinique (`PatientPolicy::updateIdentity`), les actes médicaux (pesées, vaccinations, visites, alertes, traitement, rappels) restent à la clinique. Servi au front en `droits.{gere_par, acces_clinique, modifier_identite}` ; côté espace `PUT /espace/animaux/{id}` (identité) et `PUT /espace/animaux/{id}/acces`, réservés au compte qui gère la fiche (403 sinon) |
| `since` | year | Client depuis |
| `treatment` | string | Traitement en cours (texte libre) |
| `nextVisit` | dérivé | Prochain RDV — dérivable des RDV |
| `lastVisitAgo` | dérivé | Dernière visite — dérivable |

Sous-collections (tables enfants) — toutes implémentées :

- **Pesées** (`weighings`) : `{ kg, d }` — historique **daté**, éditable
  (ajout du jour, correction, suppression). Le seed en génère 8 espacées
  de 3 mois. Table `pesees` : `patient_id`, `kg` decimal, `date`.
- **Vaccinations** (`vaccinations`) : `{ name (maladies), brand
  (marque), lot, vet, ago, due }`. `ago`/`due` sont des jours relatifs
  de démo — en base : `injectee_le` date, `echeance_le` date. Le
  catalogue (`VACCINE_CATALOG` par espèce) porte marque, maladies
  couvertes et périodicité `years` (1 an, rage 3 ans). Une injection du
  jour génère un n° de lot et écrase la ligne du même vaccin. Statut
  d'échéance affiché : **ÉCHU** / **BIENTÔT** / **À JOUR**.
- **Visites** (`visits`) : `{ ago → date, reason, vet, note }`.
- **Documents** (`documents`) : `{ name (PDF), ago → date }`. Les
  certificats et consentements générés s'y archivent (voir sections 13
  et 15).
- **Imageries** (`imagings`) : `{ type thorax|abdomen|membre, ago →
  date, seed }` — le `seed` génère le cliché procédural de la
  visionneuse DICOM (démo, non persisté tel quel ; en prod : fichier
  DICOM stocké).
- **Relevés ophtalmologiques** (`ophRecords`) : `{ d date, sOD, sOG
  (Schirmer OD/OG), pOD, pOG (PIO OD/OG), fluo pos|neg, notes }`.
- **Relevé dentaire** (`tools.dentPatMaps`) : odontogramme Triadan —
  map `{ n° de dent (Triadan) : état }` avec état ∈ `tartre`,
  `gingivite`, `parodontite`, `fracture`, `absente` (absence de clé =
  sain). Table `releves_dentaires` : `patient_id`, `dent` smallint,
  `etat` enum, ou un relevé daté avec lignes si l'historique compte.
- **Rappels coupés** (`remOff`) : booléen par patient — coupe les
  rappels automatiques pour ce patient (colonne
  `patients.rappels_coupes`).

### Codes espèce

`CH` Chien · `CT` Chat · `LP` Lapin · `FU` Furet · `RG` Rongeur ·
`OI` Oiseau · `RP` Reptile

---

## 5. Client / Propriétaire

| Champ front | Type suggéré | Description |
|---|---|---|
| `id` | PK | `o<oi>` |
| `civ` | enum nullable | `M.` \| `Mme` — vide pour un propriétaire créé à la main (le formulaire ne la demande pas) |
| `first`, `last` | string | Prénom / nom |
| `phone` | string | |
| `mail` | string | |
| `street` | string | Rue + n° |
| `npa`, `loc` | string | NPA suisse (4 chiffres) + localité |
| `since` | year | Dérivé : min des `since` de ses animaux |

Relations et dérivés implémentés :

- **1 client — N patients** (liste triable par nom / localité / nombre
  d'animaux). La modification d'une fiche propriétaire se répercute sur
  les fiches patients du foyer (nom, téléphone, mail) — disparaît avec
  de vraies FK.
- **Solde dû** = somme des `debt` de ses animaux.
- **Compteur de no-show** (badge « NO-SHOW ×N ») — cf. section 3.
- **Compte espace client** : au front, simulé par un hash du nom
  (`hasAccount`) ; en base, relation réelle `clients 0..1 comptes` —
  conditionne le canal d'envoi des devis (portail vs papier) et les
  notifications.

### Compte espace client (portail, non porté) & authentification

Le portail du proto stocke un utilisateur et ses animaux dans un format
simplifié. En production : **vues du même modèle patient/client**
filtrées par le compte connecté (le `localStorage vet-espace-*` du proto
est de la simulation, non portée).

**Nouveau depuis le portage** : la frontière d'authentification existe
(`src/api/auth.js`, mock aux signatures stables + store `auth`, session
en mémoire). Contrat attendu de l'API : `checkEmail(email) → { known }`,
`loginWithPasskey(email) → { user }` (WebAuthn), `sendEmailCode(email)`,
`verifyEmailCode(email, code) → { user }` — profil `{ mail, first,
last }`. L'utilisateur connecté sert aussi d'**acteur des audits de
RDV** (section 3).

---

## 6. Facturation & caisse (store `invoices`)

### Facture

| Champ front | Type suggéré | Description |
|---|---|---|
| `id` | PK | `ci1`… |
| `n` | string unique | « FC-2026-0341 » — séquence annuelle (voir Numérotations) |
| `date`, `time` | datetime | Émission (au front « JJ.MM.AAAA » + « HH:MM » — en base un datetime) |
| `pat`, `sp`, `ow` | dénormalisés → `patient_id` | Patient, espèce, propriétaire |
| `lane` | FK → ligne / praticien | Praticien facturant |
| `clin` | FK → clinique | Clinique d'émission |
| `apptId` | FK nullable → RDV | Facture issue d'un RDV (unique par RDV — la réouverture retrouve la facture) |
| `st` | enum | `due` (à encaisser) \| `payee` |
| `pm` | enum nullable | `tpe` \| `twint` \| `cash` |
| `paidAt` | date nullable | Date d'encaissement |
| `ins` | string nullable | Assureur (« Animalia », « Epona », « SantéVet ») |
| `stocked` | bool | Sortie de stock des lignes `med` déjà effectuée (évite le double décrément) |

### Lignes de facture

`{ t (libellé), qty, chf (prix unitaire TTC), kind: acte | med,
prodId? }` — table `facture_lignes` : `facture_id`, `libelle`,
`quantite`, `prix_chf`, `type`, `produit_id` nullable. Le total est
dérivé (`Σ qty × chf`), la TVA extraite au taux normal **8.1 %**
(`ht = ttc / 1.081`).

### Prescription jointe (`rx`)

`{ n (nom), dose, mode: stock (délivré par la clinique) | pharma
(à retirer en pharmacie) }` — imprimable depuis l'encaissement.
**Recouvrement avec le registre Ordonnances** (section 12) : la fin de
consultation crée les deux. Décision ouverte : à terme, l'ordonnance
est l'entité de référence et la facture la référence
(`facture.ordonnance_id` nullable) plutôt que de dupliquer les lignes.

### Encaissement

État transitoire d'UI (`enc`), pas une entité : choix du moyen de
paiement (TPE Stripe Terminal / TWINT simulés avec latence, espèces
avec rendu calculé), ajout d'actes (catalogue `ACTES`) et de
médicaments (stock → ligne + prescription ; rupture → prescription
pharmacie sans ligne), « payer plus tard » (la facture reste `due`,
**le stock sort quand même**).

### Catalogue d'actes (`ACTES`)

11 prestations avec prix CHF (consultation 85, suivi 55, urgence 140,
vaccination 48, contrôle 45, chirurgie 420, radio 2 clichés 120, télé
55, fin de vie 180, labo 96, détartrage 260) + mapping type de RDV →
acte (`TYPE_ACTE`). **Attention** : la fin de consultation utilise un
second catalogue aux prix différents — cf. Décisions ouvertes.

### Décomptes d'assurance

Sur une facture : affectation d'un assureur (`ins`) + PDF de décompte
(part remboursable estimée 80 %), puis suivi de la demande
(`claimStatus` : `todo` À ENVOYER → `sent` ENVOYÉE → `paid`
REMBOURSÉE). Table `decomptes_assurance` : `facture_id`, `assureur`,
`statut`, horodatages.

### Clôture de caisse (Z)

| Champ front | Type | Description |
|---|---|---|
| `n` | string | « Z-2026-041 » — séquence |
| `date`, `time`, `by` | datetime + FK user | Arrêtée par |
| `tpe`, `twint`, `cash` | decimal | Totaux encaissés par moyen depuis la dernière clôture |
| `fond` | decimal | Fond de caisse initial |
| `counted` | decimal | Espèces comptées (comptage par coupures — billets 200/100/50/20/10, pièces 5/2/1/½) |
| `ecart` | decimal | `counted − (fond + cash)` — justification obligatoire si ≠ 0 |
| `depot`, `newFond` | decimal | Dépôt en banque, nouveau fond |
| `nInv`, `invIds` | int + pivot | Factures couvertes par cette clôture |
| `note` | text | |

Règles implémentées : une facture payée n'est « à clôturer » que si
aucune clôture ne la couvre ; pas de rapprochement TPE / TWINT manuel
(les cases à cocher du prototype sont retirées) : carte et TWINT
encaissés par Stripe sont vérifiés d'office (voir ci-dessous). Exports :
Z PDF, décompte TVA mensuel PDF, journal des ventes CSV (séparateur `;`,
BOM UTF-8, compatible Banana / Abacus).

### Paiements Stripe (`paiements`, `terminaux_paiement`)

La carte et TWINT passent par Stripe ; les espèces restent saisies à la
caisse.

| Champ | Type | Notes |
|---|---|---|
| `facture_id`, `clinique_id` | FK | Une ligne par TENTATIVE |
| `moyen` | enum | `tpe` \| `twint` |
| `montant` | decimal | Total TTC au lancement |
| `stripe_payment_intent_id` | string unique | Intention Stripe (CHF, `allowed_payment_method_types`) |
| `terminal_paiement_id` | FK nullable | Terminal choisi (carte) |
| `statut` | enum | `en_attente` → `reussi` \| `echoue` \| `annule` |
| `erreur` | string | Message lisible (refus, terminal hors ligne…) |
| `url_twint` | text | Page TWINT de Stripe, affichée en QR à la caisse |

- **Carte** : intégration Terminal pilotée par le serveur (WisePOS E,
  S700). L'API crée l'intention puis `process_payment_intent` sur le
  lecteur de la clinique. Après un refus, la même intention est
  représentée (règle Stripe contre les doubles débits) ; une nouvelle
  tentative annule les précédentes.
- **TWINT** : intention confirmée côté serveur avec `return_url`
  (`FRONTEND_URL/paiement-termine`) ; la caisse affiche l'URL de
  redirection en QR, le client la scanne.
- **Issue** : webhook `POST /api/v1/stripe/webhook` (signé ;
  `payment_intent.succeeded|payment_failed|canceled`,
  `terminal.reader.action_failed`), dédupliqué par id dans
  `evenements_stripe` ; une intention inconnue est ignorée. La caisse
  interroge aussi `GET /facturation/paiements/{id}` (filet sans
  webhook). Réussie, la tentative encaisse la facture une seule fois
  (verrou) — stock et statut comme l'encaissement manuel.
- **Terminaux** : appairage par le code du lecteur ; la clinique reçoit
  sa *location* Stripe (`cliniques.stripe_location_id`) au premier.
- **Clôture** : la liste des factures expose `paye_par_stripe` ; les
  totaux carte / TWINT encaissés par Stripe sont vérifiés d'office.

---

## 7. Devis (store `devis`)

| Champ front | Type suggéré | Description |
|---|---|---|
| `id` | PK | |
| `n` | string unique | « DV-2026-018 » |
| `date` | date | |
| `pat`, `sp`, `ow` | dénormalisés → `patient_id` | |
| `motif` | string | « Ostéosynthèse fémur droit » |
| `lines` | table enfant | Même forme que les lignes de facture |
| `remise` | tinyint % | Remise commerciale |
| `alea` | tinyint % | Aléa affiché (fourchette haute) — informatif |
| `validDays` / `validUntil` | int + date | Validité (30 j par défaut) ; `validUntil` posée à l'envoi |
| `acomptePct` | tinyint % | Acompte demandé |
| `cond` | text | Conditions libres |
| `st` | enum | `brouillon` → `envoye` → `accepte` \| `refuse` ; `expire` est **dérivé** (envoyé + validité dépassée), pas stocké |
| `canal` | enum nullable | `portail` (espace client) \| `papier` — choisi automatiquement selon le compte du propriétaire |
| `signedAt`, `signedBy` | datetime + string | Acceptation (signature électronique portail ou « bon pour accord » papier) |
| `acompteInv`, `factureInv` | FK nullable → factures | Acompte facturé / facture finale (la finale déduit remise et acompte en lignes négatives) |

Cycle implémenté : brouillon (seul supprimable) → envoi (canal selon
compte) → acceptation (portail ou papier) / refus / expiration dérivée ;
relance ; duplication (repart en brouillon) ; PDF.

---

## 8. Hospitalisation (store `hosp`)

| Champ front | Type suggéré | Description |
|---|---|---|
| `id` | PK | |
| `clin` | FK → clinique | |
| `box` | tinyint | N° de box (capacité par clinique : `boxCount`) |
| `patientId` | FK → patient | **Vraie FK** (pas de liaison par nom) |
| `motif` | string | |
| `st` | enum | `stable` \| `surv` (surveillance) \| `critique` |
| `vet` | string → FK praticien | Vétérinaire référent |
| `inD` / `outL` | texte libre en démo | **En base : `admis_le` datetime, `sortie_prevue` texte ou datetime, `sorti_le` datetime nullable** |
| `perf` | string nullable | Perfusion en cours (« Ringer lactate · 45 ml/h ») |
| `note` | text | |

Tables enfants :

- **Soins** (`cares`) : `{ t (minutes du jour → heure), act (libellé),
  med (FK produit nullable), done }`. « Soin fait » décrémente le stock
  **de la clinique du séjour** si un produit est lié. Soins planifiables
  à la volée.
- **Constantes** (`consts`) : `[heure, T°, FC, FR]` — table
  `releves_constantes` : `sejour_id`, `heure`, `temperature`, `fc`,
  `fr`.

**Sortie — jamais de suppression (implémenté)** : la sortie renseigne
`outAt` (→ `sorti_le` datetime en base) et le séjour est conservé ;
les écrans ne listent que les séjours en cours (`outAt` null), le box
est libéré. Même principe que les RDV annulés — l'historique
d'hospitalisation d'un patient a de la valeur. (Le proto supprimait le
séjour : divergence assumée.)

Facturation du séjour à la sortie : forfait journalier selon le statut
(stable 65 / surveillance 95 / critique 140 CHF/j), perfusion 38 CHF/j,
soins réalisés 15 CHF/soin — facture créée en caisse + encaissement.
Le nombre de jours est estimé du libellé d'entrée en démo ; en base il
se calcule des dates réelles.

---

## 9. Laboratoire (store `labs`)

| Champ front | Type suggéré | Description |
|---|---|---|
| `id` | string unique | « A-26-0141 » — séquence |
| `patientId` | FK → patient | |
| `status` | enum | `attente` (prélevé) → `cours` (analyse) → `valid` (à valider) → `ok` (validé signé) |
| `when` | texte relatif en démo | **En base : `preleve_le` datetime** |
| `panels` | array → pivot | Panels demandés (catalogue ci-dessous) |
| `prio` | enum | `std` \| `urg` |
| `by` | FK praticien | Prescripteur |
| `results` | table enfant | Par panel, liste de `{ n (paramètre), u (unité), lo, hi (bornes), v (valeur), flag '' \| H \| L }` |
| `note` | text | |
| `validatedBy` | FK praticien nullable | Signataire de la validation |

Catalogue de panels (`LAB_PANELS`) : `nfs` (EDTA), `bioch` (SEC),
`iono` (HÉPARINE), `t4` (SEC), `urine` (URINE), `coag` (CITRATE) — avec
paramètres, unités et bornes de référence. En base : tables
`panels_labo` et `panel_parametres` (référentiel), `analyses`,
`analyse_panels`, `analyse_resultats`. Les tubes affichés dérivent des
panels.

---

## 10. Imagerie (store `imaging`)

| Champ front | Type suggéré | Description |
|---|---|---|
| `id` | PK | |
| `patientId` | FK → patient | |
| `ix` | int | Index du cliché dans la fiche patient (−1 pour US/ES) — artefact de démo, remplacé par une FK vers le fichier |
| `mod` | enum | `DX` (radio) \| `US` (écho) \| `ES` (endoscopie) |
| `label` | string | « Radiographie thorax » |
| `inc` | string | Incidence / sonde (« Profil droit · Face ») |
| `ago` | jours relatifs → date | `realise_le` |
| `by` | FK praticien | Prescripteur / opérateur |
| `done` | bool | Compte rendu signé |
| `f` | text | Constatations |
| `c` | text | Conclusion |
| `vby` | FK praticien nullable | Signataire |

Trames de compte rendu par modalité (`REPORT_TEMPLATES`) — gabarits,
côté référentiel applicatif. La visionneuse DICOM du front génère des
clichés procéduraux (démo) ou charge un `.dcm` réel ; en production les
fichiers DICOM sont stockés et rattachés à l'examen.

---

## 11. Bloc opératoire (store `bloc`)

| Champ front | Type suggéré | Description |
|---|---|---|
| `id` | PK | |
| `t` | minutes → datetime | Heure de début programmée |
| `dur` | int min | Durée prévue (min 10) |
| `patientId` | FK → patient | **Vraie FK** |
| `proc` | string | Procédure (« Ovariohystérectomie de convenance ») |
| `surgeon` | FK praticien | |
| `salle` | FK → salle de bloc | Liste gérable (cf. section 2) |
| `st` | enum | `prevu` → `prep` → `cours` → `reveil` → `fini` |
| `ck` | map → table enfant | Checklist de sécurité — 6 points de base : `jeun`, `consent`, `premed`, `iv`, `atb`, `mat` |
| `ckx` | array | Points de checklist **ajoutés** à la volée |
| `anesth` | array text | Protocole anesthésique (4 lignes : prémed, induction, maintien, analgésie) |
| `log` | table enfant | Journal des transitions `{ st, at }` — audit du cycle |
| `t0` | minutes nullable | Heure réelle d'incision (chrono) |
| `note` | text | |

Règles implémentées : **le passage en `cours` (incision) est bloqué
tant que la checklist n'est pas complète** ; le point `consent` ne se
coche que par la **signature du consentement éclairé** (dessinée à
l'écran, « archivée au dossier » → en base : document rattaché au
patient + horodatage). Suppression d'une salle refusée si des
interventions non terminées y sont rattachées.

---

## 12. Ordonnances (store `ordos`)

| Champ front | Type suggéré | Description |
|---|---|---|
| `id` | PK | |
| `n` | string unique | « RX-2026-058 » — séquence |
| `patientId` | FK → patient | **Vraie FK** |
| `date` | date | Datée du jour à la signature |
| `by` | FK praticien | Signataire (« Dr Meyer ») |
| `lines` | table enfant | `{ pr (FK produit), poso (texte), dur (texte, défaut « Selon besoin ») }` |
| `renew` | tinyint | Renouvellements restants (0–3) |
| `st` | enum | `active` (EN COURS) \| `stop` (CLÔTURÉE) |
| `note` | text | Note pour le propriétaire |

Règles implémentées :

- **Signature** = création + **sortie de stock immédiate** (une unité
  par ligne, clinique `c1` au front — en base : clinique de l'acte),
  annulable (l'annulation supprime l'ordonnance et restaure le stock —
  seul cas de suppression, dans la fenêtre d'undo).
- **Renouvellement** = nouvelle ordonnance datée du jour avec
  `renew − 1`, l'ancienne passe à `stop`. Jamais de modification d'une
  ordonnance signée.
- **Clôture** manuelle possible (`stop`) — l'ordonnance n'est plus
  délivrable en boutique/stocks.
- Créée aussi par la **fin de consultation** (médicaments prescrits) —
  même entité, `renew` 0.
- Les produits `isRx` (« sur ordonnance ») ne sont délivrables que sur
  prescription active.

---

## 13. Dossier médical transverse (store `records`)

### Compte rendu SOAP

`crMap` : un CR par RDV — `{ s (motif & anamnèse), o (examen clinique),
a (diagnostic), p (traitement & plan) }`. Table `comptes_rendus` :
`rendez_vous_id` unique, quatre champs texte, signataire, horodatage.
La présence d'un CR est affichée sur le RDV (badge « CR à rédiger » /
rédigé dans « Ma journée »). PDF signé.

### Enregistrement et transcription de la consultation (19.10.2026)

Le vétérinaire enregistre la consultation (accord du propriétaire
demandé oralement, rien n'est tracé). Pas de temps réel : l'audio est
envoyé après coup, transcrit, **effacé**, puis un brouillon SOAP est
rédigé ; le vétérinaire le relit dans la fenêtre du compte rendu et sa
validation enregistre le CR ci-dessus.

- `enregistrements_consultation` (niveau organisation) : `rendez_vous_id`,
  `clinique_id`, `user_id`, `statut` (`enregistrement` → `transcription`
  → `redaction` → `pret` → `valide` ; `erreur` + `erreur` =
  `transcription` | `redaction`, relançable), `parties_attendues`
  (connu à « terminer »), `brouillon` chiffré `{s, o, a, p, doutes[]}` ;
  un doute = `{rubrique, passage, entendu, raison}`, le passage figurant
  mot pour mot dans sa rubrique (souligné à l'écran : accepter la
  proposition ou remettre ce qui a été entendu), ou vide (note).
  Plusieurs parties par consultation (arrêt sur silence, reprise) ; un
  brouillon non validé se rouvre pour ajouter une partie.
- `parties_enregistrement` : `numero`, `statut` (`envoi` | `recue` |
  `transcrite` | `erreur`), `format`, `morceaux`, `fichier` (null dès la
  transcription), `segments` chiffrés `[{voix, debut, fin, texte}]`,
  `duree_secondes` (durée transcrite, facturée).
- Audio reçu par morceaux d'au plus 1 Mo (`POST
  enregistrements/{id}/parties/{numero}`), assemblé quand tous sont là ;
  un morceau renvoyé remplace le précédent. Audio non transcrit effacé
  après 7 jours (`transcriptions:purger`).
- Fournisseurs remplaçables : `Transcripteur` (Deepgram, `nova-3-medical`
  en `multi`, voix séparées, mots rares, `mip_opt_out`) et `Redacteur`
  (Claude, JSON imposé, n'invente rien, rubrique vide si rien n'est dit).
  Mots rares : liste de l'organisation (`organisations.termes_transcription`),
  race, vaccins et médicaments du patient, produits les plus prescrits,
  dans la limite d'environ 100 mots.
- Facturation à l'usage : 5 centimes la minute (2 CHF les 40 min, sans
  TVA), total de la **période d'abonnement** arrondi à la minute ; ligne
  ajoutée à la facture du renouvellement (ou du changement de formule qui
  clôt la période, ou facture finale à la fin de l'abonnement) ;
  `facturations_transcription` (une ligne par période, jamais deux fois,
  avec la facture). Plafond de l'organisation par période
  (`organisations.plafond_transcription`, 200 CHF par défaut) et budget Deepgram de la plateforme en dollars, vide = illimité (alerte e-mail à 80 % et
  100 %) : ils bloquent le début d'un enregistrement, jamais une
  consultation en cours. Abonnement en cours requis. Les
  administrateurs de l'organisation (rôles du domaine organisation)
  sont prévenus par e-mail à 80 % puis à 100 % de leur plafond ; plafond
  et mots rares se règlent dans Réglages, onglet Transcription.

### Carnet de santé & vaccination (modale carnet)

**Artefact de démo à ne pas reproduire** : la modale carnet est indexée
par la clé « nom|propriétaire » (`carnetKey`) avec des maps parallèles
(`carnetMap` lignes de vaccination, `chipMap` puce, `amicus` statut) et
des valeurs par défaut générées par hash quand la fiche patient
n'existe pas (RDV de patients hors référentiel). En base, **tout vit
sur le patient** : la puce (`patients.chip`), les vaccinations
(section 4), le statut AMICUS (ci-dessous). La saisie de puce (15
chiffres, format « 756 … ») et la déclaration AMICUS écrivent déjà sur
la fiche quand elle existe.

### Certificats et documents générés

Certificat de vaccination (carnet), certificats officiels (bonne santé
avec validité 10/30/90 j, antirabique, voyage avec pays de destination,
attestation d'euthanasie avec motif), consentement de bloc signé,
comptes rendus PDF : tous « archivés au dossier » d'après l'UI —
en base : table `documents` du patient (section 4) avec type, fichier,
signataire, date. Le front ne fait que télécharger le PDF (démo).

---

## 14. Produits & stocks (store `products`)

| Champ front | Type suggéré | Description |
|---|---|---|
| `id` | PK | `pr01`… |
| `name` | string | « Meloxicam 1,5 mg/ml · 100 ml » |
| `cat` | enum | `med` \| `ali` \| `soin` \| `apara` \| `phyto` \| `mat` |
| `ref` | string | Référence interne (« MED-0114 ») |
| `price` | decimal | Prix de vente TTC CHF |
| `cost` | decimal | Prix d'achat |
| `unit` | string | flacon / boîte / dose / sac / kit / pièce / carton |
| `min` | int | Seuil d'alerte de stock |
| `isShop` | bool | Visible en boutique |
| `isRx` | bool | Sur ordonnance — délivrable uniquement sur prescription active |
| `exp` | string « MM.YYYY » | Péremption (par lot en production) |
| `supplier` | string → FK fournisseur | |
| `stock` | pivot | **Quantité par clinique** (`{ c1, c2 }`) |
| `ean` | string | Code-barres (recherche caisse/stocks) — ajouté au portage |
| `promo` | tinyint % | Promotion vitrine (prix effectif dérivé) — ajouté |
| `star` | bool | Produit vedette de la vitrine — ajouté |

Mouvements de stock implémentés (décréments) : encaissement d'une ligne
`med` (une fois par facture, flag `stocked`), signature d'ordonnance,
soin d'hospitalisation avec produit lié, vaccin de fin de consultation
(`pr04`), prescription phyto de la fiche patient (`takeOne` : clinique
qui a du stock, `c1` en priorité). En production : table
`mouvements_stock` (produit, clinique, quantité ±, motif, référence
vers l'acte source) plutôt que des décréments silencieux — les undo du
front (10 s) deviennent des mouvements inverses.

L'écran Stocks affiche déjà un journal de mouvements par produit
(`movements` : libellé, ± quantité, clinique) — généré en démo, enrichi
en tête par les gestes réels : c'est la préfiguration de
`mouvements_stock`. Les **ajustements manuels sont motivés** (enum :
`recep` réception manuelle · `inv` correction d'inventaire · `casse` ·
`peremp` périmé écarté · `usage` usage interne).

### Commandes fournisseurs (écran Stocks, porté)

| Champ front | Type suggéré | Description |
|---|---|---|
| `n` | string unique | « BC-2026-031 » — séquence |
| `sup` | string → FK fournisseur | |
| `dest` | FK → clinique | Clinique de livraison |
| `st` | enum | `draft` (brouillon) → `sent` (envoyée) → `rec` (réceptionnée) |
| `date`, `eta`, `recAt` | libellés en démo | En base : `envoyee_le`, `receptionnee_le` datetimes |
| `lines` | table enfant | `{ prodId (FK), q, cost (prix d'achat) }` |

Règles : génération automatique de brouillons groupés **par
fournisseur** pour les articles sous seuil sans commande ouverte ;
suppression d'une commande possible ; la **réception** incrémente le
stock de la clinique de destination, trace les mouvements et crée un
lot. PDF de bon de commande.

### Lots & péremptions (écran Stocks, porté)

`lots` : `{ prodId, lot (n°), exp « MM.YYYY », qty, clin }` —
répartition FEFO affichée par urgence (périmé / < 3 mois / < 6 mois).
**Écarter un lot** décrémente le stock de sa clinique (mouvement motivé
« Péremption ») et supprime le lot. La réception d'une commande crée un
lot. Confirme le « par lot en production » du champ `exp`.

### Boutique (écran porté)

- **Commandes en ligne** : `{ id « C-104 », client (nom), scope
  (clinique de retrait), n (nb articles), sum (résumé), tot, st: prep →
  ready → done, time }` — préparation et remise au client, notification.
  En base : `commandes_boutique` + lignes, FK client/clinique.
- **Caisse comptoir** : panier par clinique (quantités bornées au stock
  local), encaissement TVA 8.1 % → décrément du stock + trace de vente.
  En base, une vente comptoir a vocation à devenir une **facture** de
  lignes `med`/produit (cf. section 6) plutôt qu'une entité parallèle —
  le front la trace aujourd'hui dans `sales` `{ n, cat, q, rev, cost,
  clin, d }` pour les chiffres 7 jours.
- **Vitrine** : visibilité (`isShop`), promotion (`promo` %), vedette
  (`star`) — champs du produit.

---

## 15. Rappels automatiques & messages

Pas d'entité « rappel » au front : l'écran Rappels **dérive** tout des
échéances vaccinales (≤ 45 jours) et des réglages. En production, il
faut journaliser les envois :

- **Réglages de clinique** (store `settings`) : `remMail`, `remSms`
  (canaux), `remDelay` (`j7` \| `j3` \| `j1` — délai avant échéance),
  `remVac` (rappels de vaccins), `remCtrl` (contrôles post-op),
  `remNoShow` (relance après no-show), `msgIn` (réception des messages
  clients), `msgReplyOnly` (réponses uniquement).
- **Coupure par patient** : `patients.rappels_coupes` (section 4).
- **Journal d'envois** (à créer) : `rappels_envoyes` — patient, vaccin/
  échéance, canal, envoyé_le, statut de délivrance. L'écran affiche
  « PROGRAMMÉ / ENVOYÉ / COUPÉ / SUSPENDU » ; programmé = dérivé,
  envoyé = journalisé.
- Le carnet permet aussi un **rappel manuel** par ligne de vaccination
  (« Rappeler le propriétaire », flag `sent`) — même journal.

---

## 16. Registres réglementaires

### AMICUS (chiens, banque de données suisse)

Statut de déclaration par chien — au front une map « nom|propriétaire »
→ `reg`, partagée entre le carnet et le registre de l'écran Outils
(défaut simulé par hash). En base : `patients.amicus_declare_le`
datetime nullable (+ éventuellement le n° de dossier AMICUS). La
déclaration exige une puce. Obligation légale : annonce sous 10 jours
(LFE / OI-BDTA) — la déclaration du front est une simulation, la vraie
intégration AMICUS est un chantier API.

### Registre des stupéfiants (OCStup, art. 62)

| Champ front | Type suggéré | Description |
|---|---|---|
| `id` | PK | |
| `d` | datetime | Date + heure du mouvement |
| `sub` | FK → produit stupéfiant | Méthadone 10 mg/ml, Kétamine 100 mg/ml, Buprénorphine 0,3 mg/ml, Diazépam 5 mg/ml |
| `mv` | decimal signé | Quantité (+ entrée / − sortie), unité ml |
| `pat` | string → FK patient nullable | Obligatoire pour une sortie ; « — » pour une réception |
| `by` | FK praticien | Signataire du mouvement |
| `motif` | string | « Induction anesthésie », « Réception grossiste — lot … » |

Solde par substance = base + Σ mouvements (alerte « STOCK BAS » < 10 ml).
Export PDF pour les contrôles cantonaux. En production, ces mouvements
devraient être un sous-ensemble signé de `mouvements_stock` (section
14) plutôt qu'un registre parallèle.

---

## 17. Réglages de clinique vs préférences d'interface

À séparer en base — le front mélange les deux dans `settings` :

**Réglages de clinique** (partagés, versionnés, canal temps réel au
branchement) : horaires (`openM`, `closeM`, `satM`, `sunOff`,
`pause*`) — **à rattacher par clinique**, mode structure
(`clinique` \| `solo`), rappels & messages (section 15), langue/région/
format de date (`lang`, `region`, `dateFmt` — démo, l'i18n réelle
viendra avec).

**Préférences utilisateur** (par compte, pas des données métier) :
thème et couleurs du logo, échelle du planning (`ppm`), ancrage,
aimant (`snapOn`/`snapMin`/`snapScroll`), groupes de lignes repliés,
menus visibles (`navOff` — sections masquées de la barre latérale,
persisté `localStorage vet-navoff`), largeur de la sidebar
(`vet-sbw`). Peuvent rester côté client ou passer dans une table
`preferences_utilisateur` clé/valeur.

L'écran Organisation et les onglets Réglages > Facturation /
Utilisation sont portés (plan SaaS, quotas, packs, moyen de paiement,
factures d'abonnement) — cf. section 2. La préférence de langue du
header (`prefs.lang`) doublonne `settings.lang` : à unifier côté compte
utilisateur.

---

## 17 bis. Site web de l'organisation (`sites_web`)

Hors prototype (demande du 07.10.2026) : chaque organisation publie un
petit site épuré depuis l'application — accueil (horaires de chaque
clinique en tête, actualités), services, équipe, contact. **Un site par
organisation** : il présente toutes ses cliniques (une organisation à
une seule clinique n'en montre qu'une). Non publié tant que
l'organisation ne l'active pas.

| Champ | Type | Description |
|---|---|---|
| `organisation_id` | FK unique | Organisation propriétaire du site |
| `slug` | string(60) unique | Adresse publique `/site/{slug}`, tirée du nom à la création |
| `est_publie` | bool | Servi publiquement seulement si vrai |
| `couleur` | enum | `sauge` \| `ocean` \| `terracotta` \| `prune` \| `ardoise` \| `perso` (teinte du rendu) |
| `couleur_perso` | `#rrggbb` nullable | Couleur libre, requise quand `couleur` = `perso` |
| `mode` | enum | `clair` \| `sombre` |
| `police` | enum | Style des titres : `moderne` \| `elegante` \| `douce` |
| `coins` | enum | `arrondis` \| `droits` |
| `logo_chemin` | string nullable | Logo de l'organisation (PNG, JPG ou WebP, 2 Mo ; pas de SVG), disque privé, servi par `/public/sites/{slug}/logo` même non publié ; exposé aussi par l'annuaire (`logo_url`) |
| `accroche`, `presentation` | string / text | Texte d'accueil |
| `tel_urgences` | string nullable | Numéro d'urgence propre au site |
| `afficher_tel`, `afficher_email` | bool | Le site affiche ou non le téléphone et l'e-mail **des cliniques** (`cliniques.tel`, `cliniques.email`) — jamais ressaisis |
| `services` | JSON `[{titre, description}]` | Ordonné ; services de départ à la création |
| `equipe_masquee` | JSON `[employe_id]` | L'équipe vient des **employés** présentables (accès actif, contrat non terminé) de toutes les cliniques ; le site ne retient que les personnes cachées |
| `actualites` | JSON `[{titre, texte, date}]` | Servies de la plus récente à la plus ancienne |

**Domaine personnalisé** (étape 1, domaine déjà possédé) : `domaine`
(unique, normalisé, punycode), `domaine_statut` (`attente` \| `actif` \|
`erreur`), `domaine_erreur`, `domaine_fournisseur_id` (Cloudflare),
`domaine_verifie_le`. L'éditeur indique l'enregistrement DNS à créer
(CNAME vers `SITES_CIBLE_CNAME`, ou A vers `SITES_IP` pour un domaine
nu) ; actif dès que le domaine pointe vers nous (et, avec Cloudflare for
SaaS, que le certificat est émis) ; revérifié toutes les 10 minutes.
`/public/domaines/{domaine}` renvoie le slug d'un domaine actif ;
`/public/domaines-autorises?domain=` répond 204/404 au serveur qui émet
les certificats à la demande.

Adresses, téléphones, e-mails, coordonnées GPS, **équipe** (nom, rôle,
clinique, photo — `employes.photo_chemin`, privée, servie par
`/public/photos/{fichier}` au nom imprévisible) et **horaires** ne sont pas
recopiés : lus sur `cliniques` et `horaires_ouverture`. La route
publique sert, pour chaque clinique, les sept jours mis en forme
(plages « 08:00 – 12:30 ») et un statut « Ouvert · jusqu'à … » /
« Fermé · ouvre … » calculé dans son fuseau, avec les mêmes bornes que
le planning (pause comprise). Édition réservée au domaine
`organisation`. L'annuaire public (`/public/cliniques`) expose sur
chaque clinique le `site_slug` publié de son organisation.

---

## 18. Numérotations

Toutes les pièces sont numérotées par séquence annuelle — en base, des
séquences par type (et par organisation), pas des `length + offset`
comme la démo :

| Pièce | Format | Exemple |
|---|---|---|
| Facture | `FC-YYYY-NNNN` | FC-2026-0345 |
| Devis | `DV-YYYY-NNN` | DV-2026-018 |
| Ordonnance | `RX-YYYY-NNN` | RX-2026-058 |
| Analyse labo | `A-YY-NNNN` | A-26-0141 |
| Clôture de caisse | `Z-YYYY-NNN` | Z-2026-041 |

---

## 19. Catalogue des vaccins et carnet (16.10.2026)

Données de plateforme (aucune organisation, aucun scope) :

- `produits_veterinaires` : les 760 médicaments vétérinaires autorisés en Suisse, importés du flux Open Government Data de Swissmedic (ZIP d'XML mensuel, sans clé). Clé : `numero_autorisation`. `categorie` : `vaccin` (code Swissmedic I), `immunologique` (AI, sérums), `medicament`. `especes` (codes `Espece`) déduit du code ATCvet et des mots du nom (« für Hunde, Katzen… ») ; `voies` : codes Swissmedic. Un produit disparu du flux est marqué `retire_le`, jamais supprimé.
- `produit_emballages` : emballages autorisés (numéro à 8 chiffres = produit + emballage, celui des codes Swissmedic et Refdata) ; `gtin` et `nom_fr` posés par la base d'articles Refdata (ZIP d'XML quotidien, clé de souscription `REFDATA_API_KEY`, passée en en-tête `X-API-Key`).
- `valences` : maladies couvertes, en français (données curées `DonneesVaccins::VALENCES`) ; `produit_valence` : lesquelles par produit, déduites de la description ATCvet (`AGENTS`) ou curées (`PRODUITS[n]['valences']`).
- `vaccin_schemas` : schéma d'emploi curé par produit (âge minimal, nombre d'injections et intervalle de la primo-vaccination, premier rappel, rappel courant, voie, remarques, source). Jamais écrasé par un import ; les sources « indicatives » restent à confirmer sur l'information professionnelle.
- `catalogue_imports` : journal (source, empreinte SHA-256, ETag annoncé par le serveur, date d'export, comptes, statut `reussi | identique | echec`). Swissmedic reçoit l'ETag du dernier fichier et répond « inchangé » sans rien envoyer ; Refdata n'annonce rien, son fichier est téléchargé puis comparé par empreinte. Un fichier inchangé n'écrit rien ; aucune archive n'est conservée sur le disque.

Commandes `catalogue:swissmedic` et `catalogue:refdata` (`--fichier`, `--force`) ; planifiées en production seulement (le 3 du mois à 4 h, le lundi à 4 h 30). Nouveaux vaccins notifiés par e-mail à `CATALOGUE_NOTIFICATION_EMAIL`.

`vaccinations` : chaque injection est une ligne (historique complet, plus d'écrasement), reliée au `produit_id` (ou saisie libre `nom`/`marque`), avec `valences` (photographie des codes au moment de l'injection), `lot`, `gtin`, `peremption_le`, `voie`, `site_injection`, `remarque`, `reaction`, `echeance_le` facultative (calculée du schéma : prochaine dose de primo, premier rappel, rappel courant). `App\Services\EtatVaccinal` dérive l'état par valence (`retard`, `primo`, `proche` à 30 jours, `ok`, `inconnu`) pour l'onglet clinique et le carnet du propriétaire.

Routes : `GET vaccins?espece=&q=`, `GET vaccins/gtin/{gtin}`, `GET|POST patients/{patient}/vaccinations`, `PUT|DELETE patients/{patient}/vaccinations/{vaccination}`.

## Annexe — récapitulatif des enums

| Enum | Valeurs |
|---|---|
| Espèce | `CH`, `CT`, `LP`, `FU`, `RG`, `OI`, `RP` |
| Type de RDV | `consult`, `vaccin`, `chir`, `ctrl`, `imagerie`, `urgence`, `euth`, `tele`, `tampon` |
| Statut de RDV | `prevu`, `salle`, `cours`, `fini`, `noshow`, `annule` (jamais de suppression) |
| Drapeau RDV | `ajeun`, `mord`, `iso`, `neuf` |
| Rôle de ligne | `consult`, `bloc`, `img` |
| Type d'absence | `conge`, `maladie`, `formation`, `recup`, `garde` |
| Statut d'absence | `ok`, `att`, `ref` |
| Mode de demande en ligne | `valider`, `confirme` |
| Statut de facture | `due`, `payee` |
| Moyen de paiement | `tpe`, `twint`, `cash` |
| Type de ligne de facture | `acte`, `med` |
| Mode de prescription | `stock`, `pharma` |
| Statut de décompte assurance | `todo`, `sent`, `paid` |
| Statut de devis | `brouillon`, `envoye`, `accepte`, `refuse` (+ `expire` dérivé) |
| Canal de devis | `portail`, `papier` |
| Statut de séjour | `stable`, `surv`, `critique` — la sortie est portée par `sorti_le` (null = en cours) |
| Statut d'analyse labo | `attente`, `cours`, `valid`, `ok` |
| Priorité labo | `std`, `urg` |
| Modalité d'imagerie | `DX`, `US`, `ES` |
| Statut d'intervention bloc | `prevu`, `prep`, `cours`, `reveil`, `fini` |
| Statut d'ordonnance | `active`, `stop` |
| État dentaire | (sain = absence), `tartre`, `gingivite`, `parodontite`, `fracture`, `absente` |
| Catégorie de produit | `med`, `ali`, `soin`, `apara`, `phyto`, `mat` |
| Civilité | `M.`, `Mme` (nullable) |
| Sexe animal | `Mâle`, `Femelle` |
| Statut de commande fournisseur | `draft`, `sent`, `rec` |
| Motif d'ajustement de stock | `recep`, `inv`, `casse`, `peremp`, `usage` |
| Statut de commande boutique | `prep`, `ready`, `done` |
| Accès d'un employé | `ok`, `sus` |
| Statut de contrat | `signe`, `attente`, `pdf` |
| Formule SaaS | `individuel`, `cabinet`, `hopital` |

---

## Décisions ouvertes

- **Catalogue de prestations unique.** Deux catalogues d'actes
  coexistent avec des prix différents : `ACTES` (caisse — vaccination
  48, chirurgie 420, fin de vie 180, radio 2 clichés 120) et
  `FC_CATALOG` (fin de consultation — vaccination 42, chirurgie 380,
  euthanasie 160, radio 1 cliché 95, plus injection SC 25, NFS 88,
  coupe de griffes 18). Fidèle au proto, mais en base il faut **une
  table `prestations` unique** (avec éventuellement des variantes),
  sinon le même acte a deux prix selon l'écran.
- **Prescription : ordonnance vs facture.** La fin de consultation crée
  l'ordonnance au registre **et** duplique les lignes en `facture.rx`.
  Cible : l'ordonnance est l'entité de référence, la facture la
  référence (impression depuis l'encaissement conservée).
- **Rôles et permissions** : à décider. Le proto esquisse quatre
  profils (Admin/Organisateur, Vétérinaire, AMV, Client) avec menus
  masqués par profil (`navHidden` : le vétérinaire ne voit pas caisse/
  boutique/stocks, l'AMV pas le bloc). Contrainte connue : **une
  assistante ne voit pas la facturation**. Question ouverte : rôles
  figés en dur ou configurables par l'organisateur ? À ne pas confondre
  avec « Menus visibles » (préférence personnelle, implémentée).
- **Rétention des données (nLPD)** : non traitée. Durées de
  conservation, anonymisation des clients/patients inactifs,
  articulation avec les statuts jamais supprimés (RDV `annule`,
  no-shows, séjours clos, ordonnances clôturées, registre des
  stupéfiants) et les journaux d'audit.
- **Deux équipes non réconciliées.** `planning.staff` (6 employés
  seedés, liés aux lignes du planning) et `org.clinics[].team` (autres
  noms, accès/contrats/licenciements) coexistent sans lien. La base
  tranche : une table `employes` unique — mais le raccord des deux
  jeux de seeds reste à faire côté front.
- **Suppressions tolérées** — à confirmer en base : brouillon de devis
  (implémenté), ordonnance dans la fenêtre d'annulation (undo 10 s),
  pesée erronée, **RDV dans la fenêtre d'undo de sa création** (entrée
  d'audit « suppression »), **absence** (rouvre la colonne),
  **relevé ophtalmologique**, **bon de commande fournisseur**, **lot
  écarté** (avec mouvement de stock), **clinique** (s'il en reste au
  moins une — à discuter : une clinique avec historique devrait être
  désactivée, pas supprimée). Tout le reste est statutaire (annulation,
  clôture, sortie), jamais supprimé.
- **Undo 10 s côté API.** Le front annule des gestes composés (fini +
  facture, signature + stock, renouvellement) dans les 10 s. Côté API :
  soit des transactions compensatoires (mouvements inverses,
  suppression dans la fenêtre), soit un délai de commit — à trancher
  avant les migrations car cela conditionne les contraintes
  d'intégrité (ex. `facture_lignes` immuables ou non).
