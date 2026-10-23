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
 *   clôturer la caisse, rembourser).
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
            self::Facturation, self::CloturerCaisse, self::Rembourser => 'caisse',
            self::Equipe, self::Rh, self::Organisation => 'equipe',
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
            self::Equipe => 'Liste de l’équipe et gardes du week-end.',
            self::Organisation => 'Cliniques, abonnement, compte de paiement, rôles et droits.',
            self::Rh => 'Embaucher, modifier les rôles et les salaires, suspendre, licencier.',
            self::Prescrire => 'Signer et renouveler une ordonnance ; le médicament sort du stock.',
            self::ValiderAnalyses => 'Valider et signer les résultats de laboratoire.',
            self::SignerComptesRendus => 'Signer les comptes rendus de consultation et d’imagerie.',
            self::Stupefiants => 'Enregistrer les entrées et sorties de stupéfiants.',
            self::CloturerCaisse => 'Arrêter la caisse du jour (clôture Z).',
            self::Rembourser => 'Rembourser un paiement et répondre aux litiges.',
        };
    }
}
