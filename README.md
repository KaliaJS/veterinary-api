<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/marque/veterinary-logo-texte-clair.png">
    <img src="docs/marque/veterinary-logo-texte-fonce.png" alt="Veterinary" width="440">
  </picture>
</p>

<p align="center">
  <strong>Le logiciel des cliniques vétérinaires suisses, et l’espace de leurs clients.</strong><br>
  API de <a href="https://veterinary.app">veterinary.app</a> · Laravel 13 · PHP 8.3
</p>

---

## Veterinary en bref

Veterinary réunit dans une seule application la journée entière d’une clinique vétérinaire, et offre à chaque propriétaire d’animal un espace gratuit, relié à sa clinique.

**Pour les cliniques**, par abonnement mensuel :

- **Rendez-vous** : planning des vétérinaires, des salles, du bloc et de l’imagerie, liste d’attente, urgences, téléconsultation, prise de rendez-vous en ligne.
- **Dossier médical** : visites, comptes rendus, vaccins sur le catalogue Swissmedic, relevés dentaires et ophtalmologiques, laboratoire, imagerie DICOM, hospitalisation, bloc opératoire, ordonnances et registre des stupéfiants.
- **Transcription des consultations** : la consultation enregistrée est transcrite, puis un brouillon de compte rendu SOAP est rédigé ; le vétérinaire le relit et le valide.
- **Gestion** : caisse et factures, devis, décomptes d’assurance, stocks et commandes fournisseurs, boutique en ligne, site web de la clinique.
- **Organisation** : plusieurs sociétés et cliniques, équipe, rôles et permissions.

**Pour les propriétaires**, gratuitement : carnet de santé et vaccins, rendez-vous en ligne, documents, messages avec la clinique, commandes en boutique, et « Mon espace », la planète où vivent leurs animaux. L’offre Plus, facultative, ouvre d’autres planètes.

## Ce dépôt

L’API REST de Veterinary. Elle sert l’application web et l’app iPhone, développées dans [veterinary-web](https://github.com/KaliaJS/veterinary-web) (Vue 3, Capacitor).

| | |
| :-- | :-- |
| **Authentification** | Jetons Sanctum, connexion par passkey ou par code envoyé par e-mail. Un jeton porte une clinique ; les permissions sont relues en base à chaque requête. |
| **Multi-clinique** | Chaque requête s’exécute dans le contexte de la clinique du jeton. Le dossier médical, lui, est partagé par toutes les cliniques de l’organisation. |
| **Accès vérifié et payant** | Une clinique s’ouvre une fois son vétérinaire retrouvé dans MedReg (ou vérifié à la main) et sa formule payée. |
| **Abonnements maison** | Stripe enregistre les cartes et les prélève ; les abonnements, les factures et les relances sont les nôtres. Renouvellement à la date anniversaire, factures sans TVA. |

## Services

| Service | Rôle |
| :-- | :-- |
| [Stripe](https://stripe.com) | Cartes enregistrées et prélèvements des abonnements ; terminal de paiement et TWINT à la caisse. |
| [Deepgram](https://deepgram.com) | Transcription des consultations (`nova-3-medical`). |
| [Claude](https://www.anthropic.com) | Brouillon du compte rendu SOAP à partir de la transcription. |
| [MedReg](https://healthreg-public.admin.ch/medreg/search) | Vérification du GLN des vétérinaires à l’inscription des cliniques. |
| [Swissmedic](https://www.swissmedic.ch) et [Refdata](https://www.refdata.ch) | Catalogue des médicaments vétérinaires et codes-barres des emballages. |
| App Store | Offre Plus achetée dans l’app iPhone. |

## Démarrer en local

Il faut [Laravel Herd](https://herd.laravel.com) (PHP 8.3 et MySQL), Composer, [Mailpit](https://mailpit.axllent.org) et la [CLI Stripe](https://docs.stripe.com/stripe-cli).

```bash
composer install
cp .env.example .env              # puis remplir les clés (voir les commentaires du fichier)
php artisan key:generate
php artisan migrate:fresh --seed  # base de démonstration
php artisan catalogue:swissmedic  # médicaments vétérinaires
php artisan catalogue:refdata     # codes-barres, demande REFDATA_API_KEY
php artisan queue:work            # transcriptions et e-mails
composer stripe:listen            # webhooks Stripe en local
```

La démonstration ouvre la clinique Deux Rives (Lausanne et Genève). On s’y connecte avec `a.meyer@deuxrives.ch` : le code de connexion arrive dans Mailpit.

## Tâches planifiées

| Commande | Quand | Rôle |
| :-- | :-- | :-- |
| `abonnements:renouveler` | toutes les heures | Renouvelle les abonnements échus et retente les prélèvements refusés. |
| `inscriptions:reessayer` | toutes les heures | Retente dans MedReg les cliniques inscrites pendant une panne. |
| `transcriptions:purger` | chaque nuit | Efface l’audio des consultations déjà transcrites. |
| `sites:verifier-domaines` | toutes les 10 minutes | Vérifie les domaines personnalisés des sites des cliniques. |
| `catalogue:swissmedic` | le 3 du mois | Met à jour les médicaments vétérinaires autorisés. |
| `catalogue:refdata` | chaque lundi | Met à jour les codes-barres des emballages. |

## Tests et style

```bash
php artisan test          # SQLite en mémoire
vendor/bin/pint --dirty   # style du code
```

## Migrations

Tant que Veterinary n’est pas en production, il y a **une migration par table**, dans son état final. Un changement de schéma se fait dans la migration de la table concernée, suivi de `php artisan migrate:fresh --seed`. Après la mise en production, on reviendra aux migrations successives.

## Documentation

- [`docs/data-model.md`](docs/data-model.md) : le modèle de données et les décisions qui le fondent.
- [`docs/branchement.md`](docs/branchement.md) : le branchement du front sur l’API.

## Licence

© 2026 [Meteora Systems Sàrl](https://veterinary.app), Froideville (Suisse). Logiciel propriétaire, tous droits réservés.
