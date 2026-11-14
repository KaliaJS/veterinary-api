<?php

namespace App\Enums;

/**
 * Droits : la base des permissions. Les rôles (configurables par
 * organisation, écran Rôles et droits) accordent des droits ; les
 * dérogations par employé ajustent au cas par cas.
 *
 * Deux sortes de droits :
 * - les domaines (accès à un pan de l'application : planning, dossier
 *   médical, caisse, stocks, bloc, équipe, organisation) ;
 * - les droits fins (23.10.2026) : les actes signés et les gestes
 *   sensibles d'un domaine, séparés pour qu'une organisation choisisse
 *   qui les fait (gérer l'équipe, signer une ordonnance, valider une
 *   analyse, signer un compte rendu, tenir le registre des stupéfiants,
 *   clôturer la caisse, rembourser) ; depuis le 14.11.2026, les droits du
 *   manager (gérer les absences, voir les chiffres), donnés au rôle
 *   Manager ou à titre personnel.
 */
enum DomainePermission: string
{
    case Planning = 'planning';
    case Patients = 'patients';
    case Facturation = 'facturation';
    case Stocks = 'stocks';
    case Bloc = 'bloc';
    case Equipe = 'equipe';
    case Organisation = 'organisation';

    case Rh = 'rh';
    case Prescrire = 'prescrire';
    case ValiderAnalyses = 'valider_analyses';
    case SignerComptesRendus = 'signer_comptes_rendus';
    case Stupefiants = 'stupefiants';
    case CloturerCaisse = 'cloturer_caisse';
    case Rembourser = 'rembourser';

    case GererAbsences = 'gerer_absences';
    case VoirChiffres = 'voir_chiffres';

    /** @return list<string> */
    public static function valeurs(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Groupe de l'écran Rôles et droits */
    public function groupe(): string
    {
        return match ($this) {
            self::Prescrire, self::ValiderAnalyses, self::SignerComptesRendus, self::Stupefiants => 'actes',
            self::Facturation, self::CloturerCaisse, self::Rembourser, self::VoirChiffres => 'caisse',
            self::Equipe, self::GererAbsences, self::Rh, self::Organisation => 'equipe',
            default => 'acces',
        };
    }

    public function libelle(): string
    {
        return match ($this) {
            self::Planning => 'Planning',
            self::Patients => 'Dossiers médicaux',
            self::Facturation => 'Caisse et facturation',
            self::Stocks => 'Stocks et boutique',
            self::Bloc => 'Bloc opératoire',
            self::Equipe => 'Voir l’équipe',
            self::Organisation => 'Administration',
            self::Rh => 'Gérer l’équipe',
            self::Prescrire => 'Signer les ordonnances',
            self::ValiderAnalyses => 'Valider les analyses',
            self::SignerComptesRendus => 'Signer les comptes rendus',
            self::Stupefiants => 'Registre des stupéfiants',
            self::CloturerCaisse => 'Clôturer la caisse',
            self::Rembourser => 'Rembourser',
            self::GererAbsences => 'Gérer les absences',
            self::VoirChiffres => 'Voir les chiffres',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Planning => 'Rendez-vous, files d’attente, absences.',
            self::Patients => 'Dossiers, carnets, examens, hospitalisation, laboratoire, imagerie.',
            self::Facturation => 'Factures, devis, encaissements.',
            self::Stocks => 'Produits, lots, commandes, vente au comptoir.',
            self::Bloc => 'Interventions chirurgicales.',
            self::Equipe => 'Qui est dans l’équipe, présent ou absent, son téléphone et son e-mail ; les gardes de chaque nuit.',
            self::Organisation => 'Cliniques, abonnement, compte de paiement, rôles et droits.',
            self::Rh => 'Embaucher, modifier les rôles et les salaires, suspendre, licencier.',
            self::Prescrire => 'Signer et renouveler une ordonnance ; le médicament sort du stock.',
            self::ValiderAnalyses => 'Valider et signer les résultats de laboratoire.',
            self::SignerComptesRendus => 'Signer les comptes rendus de consultation et d’imagerie.',
            self::Stupefiants => 'Enregistrer les entrées et sorties de stupéfiants.',
            self::CloturerCaisse => 'Arrêter la caisse du jour (clôture Z).',
            self::Rembourser => 'Rembourser un paiement et répondre aux litiges.',
            self::GererAbsences => 'Valider ou refuser les congés, poser une absence pour un autre, voir la raison des absences et les soldes de congés.',
            self::VoirChiffres => 'Chiffre d’affaires et marge de l’organisation : statistiques de la caisse et de la boutique, exports pour la fiduciaire (avec « Clôturer la caisse », remettre le mois).',
        };
    }
}
