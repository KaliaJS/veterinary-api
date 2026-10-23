<?php

namespace Database\Seeders;

use App\Enums\Espece;
use App\Enums\ModeDemandeRendezVous;
use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\LignePlanning;
use App\Models\ListeAttente;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\UrgenceAttente;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Rendez-vous du jour du front (planning.seed.js, minutes depuis minuit
 * converties en datetimes), chevauchements compris — le recouvrement
 * d'intervalles est une donnée voulue du jeu de démo. Quand la fiche
 * patient existe, le RDV la référence (patient_id/client_id) et les
 * dénormalisations sont réalignées dessus, comme la migration du front.
 * Alimente aussi les files : urgences, demandes en ligne, liste
 * d'attente. Les minutes du front sont des heures LOCALES de la
 * clinique (fuseau de la clinique) : elles sont converties en UTC à
 * l'enregistrement, comme tout datetime.
 */
class RendezVousSeeder extends Seeder
{
    use WithoutModelEvents;

    /** [ligne, début min, durée min, patient, espèce, propriétaire, type, statut, drapeaux, motif, arrivée min] */
    private const RENDEZ_VOUS = [
        ['Dr Meyer', 480, 20, 'Nala', 'CT', 'Mme Lefèvre', 'vaccin', 'fini', [], 'Rappel typhus / coryza', null],
        ['Dr Meyer', 500, 30, 'Gaspard', 'CH', 'M. Ourdane', 'consult', 'fini', [], 'Boiterie postérieur droit', null],
        ['Dr Meyer', 540, 30, 'Zia', 'CH', 'Mme Bruel', 'consult', 'fini', [], 'Dermatite récidivante', null],
        ['Dr Meyer', 580, 20, 'Praline', 'CT', 'M. Vidal', 'ctrl', 'fini', [], 'Contrôle post-stérilisation', null],
        ['Dr Meyer', 660, 40, 'Chaussette', 'CH', 'Mme Ancel', 'consult', 'cours', [], 'Perte d’appétit depuis 4 jours', null],
        ['Dr Meyer', 700, 30, 'Java', 'CH', 'M. Perrot', 'consult', 'salle', [], 'Vomissements intermittents', 652],
        ['Dr Meyer', 730, 45, 'Ficelle', 'CH', 'Mme Roux', 'euth', 'prevu', [], 'Accompagnement de fin de vie', null],
        ['Dr Meyer', 840, 20, 'Cannelle', 'CH', 'M. Kessler', 'vaccin', 'prevu', [], 'Rappel CHPPiL', null],
        ['Dr Meyer', 860, 30, 'Sushi', 'CT', 'Mme Diaz', 'consult', 'prevu', [], 'Prurit et léchage excessif', null],
        ['Dr Meyer', 900, 30, 'Iroise', 'CH', 'M. Baldi', 'consult', 'prevu', ['iso'], 'Toux persistante depuis 6 jours', null],
        ['Dr Meyer', 930, 20, 'Filou', 'CH', 'M. Baranov', 'tele', 'prevu', [], 'Contrôle vidéo, plaie post-opératoire', null],
        ['Dr Meyer', 960, 30, null, null, null, 'tampon', 'prevu', [], null, null],
        ['Dr Meyer', 990, 20, 'Pistache', 'CT', 'Mme Nkomo', 'vaccin', 'prevu', [], 'Primo-vaccination', null],
        ['Dr Meyer', 1050, 30, 'Bourbon', 'CH', 'M. Tissier', 'consult', 'prevu', [], 'Bilan gériatrique annuel', null],
        ['Dr Meyer', 1110, 30, 'Écorce', 'CH', 'Mme Halimi', 'consult', 'prevu', [], 'Otite à répétition', null],

        ['Dr Solal', 480, 30, 'Pixel', 'LP', 'M. Andrieu', 'consult', 'fini', [], 'Perte de poids', null],
        ['Dr Solal', 510, 20, 'Loki', 'CH', 'Mme Vasseur', 'ctrl', 'fini', [], 'Retrait de points', null],
        ['Dr Solal', 570, 20, 'Biscuit', 'FU', 'M. Léandre', 'vaccin', 'fini', [], 'Vaccin maladie de Carré', null],
        ['Dr Solal', 600, 30, 'Orion', 'CH', 'Mme Cortès', 'consult', 'fini', [], 'Contrôle cardiaque', null],
        ['Dr Solal', 670, 20, 'Nino', 'CH', 'M. Fabre', 'vaccin', 'cours', [], 'Rappel annuel', null],
        ['Dr Solal', 700, 20, 'Pilou', 'CH', 'Mme Ottavi', 'consult', 'salle', [], 'Cochon d’Inde, grattage intense', 668],
        ['Dr Solal', 725, 30, 'Ourson', 'CH', 'M. Grangé', 'consult', 'prevu', ['neuf'], 'Première visite, chiot 3 mois', null],
        ['Dr Solal', 840, 30, null, null, null, 'tampon', 'prevu', [], null, null],
        ['Dr Solal', 870, 20, 'Prune', 'CH', 'Mme Weiss', 'ctrl', 'prevu', [], 'Suivi traitement anti-inflammatoire', null],
        ['Dr Solal', 900, 30, 'Ramen', 'CH', 'M. Ilyas', 'consult', 'prevu', ['neuf'], 'Boiterie patte arrière, réservé en ligne', null],
        ['Dr Solal', 960, 30, 'Câline', 'CT', 'Mme Perez', 'vaccin', 'prevu', [], 'Rappel vaccin, réservé en ligne', null],
        ['Dr Solal', 1080, 20, 'Tofu', 'CT', 'M. Baranov', 'tele', 'prevu', [], 'Suivi alimentation rénale', null],

        ['Dr Adjei', 540, 40, 'Tempête', 'CT', 'Mme Sicard', 'urgence', 'fini', [], 'Détresse respiratoire', null],
        ['Dr Adjei', 600, 30, 'Mistral', 'CH', 'M. Dorel', 'consult', 'fini', [], 'Plaie coussinet', null],
        ['Dr Adjei', 660, 45, 'Bagheera', 'CH', 'Mme Fauré', 'urgence', 'cours', ['mord'], 'Abcès infecté à la base de la queue', null],
        ['Dr Adjei', 710, 20, 'Nougat', 'CH', 'M. Rigal', 'ctrl', 'noshow', [], 'Contrôle poids / diabète', null],
        ['Dr Adjei', 840, 45, null, null, null, 'tampon', 'prevu', [], null, null],
        ['Dr Adjei', 900, 30, 'Kito', 'CH', 'Mme Aubin', 'consult', 'prevu', [], 'Diarrhée chronique', null],
        ['Dr Adjei', 960, 30, null, null, null, 'tampon', 'prevu', [], null, null],
        ['Dr Adjei', 1020, 20, 'Yuki', 'CT', 'M. Lemoine', 'ctrl', 'prevu', [], 'Contrôle post-opératoire', null],
        ['Dr Adjei', 1080, 40, 'Silva', 'CH', 'Mme Roche', 'consult', 'prevu', [], 'Bilan pré-anesthésique', null],

        ['Bloc 1', 480, 90, 'Mochi', 'CT', 'Mme Girard', 'chir', 'fini', ['ajeun'], 'Stérilisation', null],
        ['Bloc 1', 585, 60, 'Tsuki', 'CH', 'M. Nadaud', 'chir', 'fini', ['ajeun'], 'Détartrage et extraction', null],
        ['Bloc 1', 660, 90, 'Isis', 'CH', 'Mme Cazals', 'chir', 'cours', ['ajeun'], 'Exérèse de masse cutanée', null],
        ['Bloc 1', 840, 75, 'Vega', 'CT', 'M. Brunel', 'chir', 'prevu', ['ajeun'], 'Stérilisation', null],
        ['Bloc 1', 960, 60, 'Gribouille', 'CH', 'Mme Ferrand', 'chir', 'prevu', ['ajeun'], 'Ablation griffe surnuméraire', null],

        ['Radio / Écho', 600, 30, 'Pépite', 'CT', 'M. Salvi', 'imagerie', 'fini', [], 'Échographie abdominale', null],
        ['Radio / Écho', 675, 30, 'Kobe', 'CH', 'Mme Thiam', 'imagerie', 'cours', [], 'Radiographie thoracique', null],
        ['Radio / Écho', 900, 30, 'Câlin', 'CH', 'M. Oury', 'imagerie', 'prevu', [], 'Échographie cardiaque', null],
        ['Radio / Écho', 1020, 30, 'Nuage', 'CT', 'Mme Belin', 'imagerie', 'prevu', [], 'Radio bassin', null],

        // Chevauchements voulus (exercent l'empilement du planning).
        ['Dr Solal', 1090, 30, 'Opale', 'CT', 'Mme Costa', 'consult', 'prevu', [], 'Éternuements en salve', null],
        ['Dr Meyer', 1050, 30, 'Perle', 'CT', 'Mme Josserand', 'ctrl', 'prevu', [], 'Suivi insuffisance rénale', null],
        ['Radio / Écho', 885, 60, 'Falco', 'CH', 'M. Weber', 'imagerie', 'prevu', [], 'Scanner abdominal complet', null],
        ['Dr Adjei', 1090, 30, 'Maki', 'CH', 'M. Delatour', 'urgence', 'prevu', [], 'Ingestion de corps étranger', null],
        ['Dr Adjei', 1095, 20, 'Griotte', 'CT', 'Mme Naveau', 'consult', 'prevu', [], 'Boiterie apparue ce matin', null],
    ];

    public function run(): void
    {
        $deuxRives = Clinique::where('nom', 'Deux Rives')->firstOrFail();
        $lignes = LignePlanning::where('clinique_id', $deuxRives->id)->get()->keyBy('nom');
        $jour = $this->minuitLocal($deuxRives);

        foreach (self::RENDEZ_VOUS as [$ligne, $debut, $duree, $nomPatient, $espece, $proprietaire, $type, $statut, $drapeaux, $motif, $arrivee]) {
            $patient = $nomPatient
                ? Patient::whereRaw('lower(nom) = ?', [mb_strtolower($nomPatient)])->first()
                : null;

            RendezVous::create([
                'clinique_id' => $deuxRives->id,
                'ligne_planning_id' => $lignes[$ligne]->id,
                'patient_id' => $patient?->id,
                'client_id' => $patient?->client_id,
                'salle_id' => null,
                'patient_name' => $nomPatient,
                'species' => $patient->espece ?? ($espece ? Espece::from($espece) : null),
                'owner_name' => $patient ? $patient->client->nom_affiche : $proprietaire,
                'type' => TypeRendezVous::from($type),
                'status' => StatutRendezVous::from($statut),
                'flags' => $drapeaux,
                'reason' => $motif,
                'starts_at' => $jour->addMinutes($debut)->utc(),
                'ends_at' => $jour->addMinutes($debut + $duree)->utc(),
                'arrived_at' => $arrivee !== null ? $jour->addMinutes($arrivee)->utc() : null,
            ]);
        }

        $this->seedFiles($deuxRives, $lignes->get('Dr Meyer'), $jour);
    }

    /** Minuit du jour courant dans le fuseau de la clinique. */
    private function minuitLocal(Clinique $clinique): CarbonImmutable
    {
        return CarbonImmutable::today($clinique->fuseau_horaire ?? 'Europe/Zurich');
    }

    private function seedFiles(Clinique $clinique, LignePlanning $ligneMeyer, CarbonImmutable $jour): void
    {
        $urgences = [
            ['Filou', 'CH', 'M. Rebours', 'Abdomen distendu, tentatives de vomissement improductives', 3, 45, '11:08'],
            ['Réglisse', 'CT', 'Mme Dubost', 'Œil gauche fermé, écoulement purulent', 2, 20, '11:15'],
            ['Plume', 'OI', 'M. Cadéac', 'Perruche, refuse de s’alimenter depuis hier', 1, 20, '10:52'],
        ];
        foreach ($urgences as [$nom, $espece, $proprietaire, $motif, $triage, $duree, $heure]) {
            UrgenceAttente::create([
                'clinique_id' => $clinique->id,
                'patient_name' => $nom,
                'species' => Espece::from($espece),
                'owner_name' => $proprietaire,
                'reason' => $motif,
                'triage' => $triage,
                'duree_minutes' => $duree,
                'arrivee_le' => $jour->setTimeFromTimeString($heure)->utc(),
            ]);
        }

        // Déposées sans compte (l'e-mail reçoit les réponses, dans Mailpit en local),
        // chacune avec l'heure choisie dans le calendrier : demain, après-demain
        $demandes = [
            ['Grisou', 'CT', 'Mme Pineau', 'pineau@exemple.ch', '079 512 44 08', 'Vomit depuis 2 jours, reste prostré', $jour->addDay()->setTime(16, 0), 30],
            ['Mango', 'CH', 'Mme Estève', 'esteve@exemple.ch', '078 220 61 35', 'Rappel vaccin + antiparasitaire', $jour->addDays(2)->setTime(9, 0), 20],
            ['Vanille', 'CT', 'M. Sorel', 'sorel@exemple.ch', '076 431 90 12', 'Griffes trop longues, gêne à la marche', $jour->addDay()->setTime(10, 20), 20],
        ];
        foreach ($demandes as [$nom, $espece, $proprietaire, $email, $telephone, $motif, $debut, $duree]) {
            DemandeRendezVous::create([
                'clinique_id' => $clinique->id,
                'patient_name' => $nom,
                'species' => Espece::from($espece),
                'owner_name' => $proprietaire,
                'email' => $email,
                'telephone' => $telephone,
                'reason' => $motif,
                'souhait' => 'Sur place · '.$debut->format('d.m.Y à H:i').' (sans compte)',
                'debut_souhaite' => $debut->utc(),
                'mode' => ModeDemandeRendezVous::AValider,
                'duree_minutes' => $duree,
                'jeton' => Str::random(48),
            ]);
        }

        $listeAttente = [
            ['Caramel', 'CH', 'Mme Weber', '079 640 22 18', 'Boiterie patte avant qui s’aggrave', 'Dès que possible', null, 20, $jour->subDay()->setTime(17, 40)->utc()],
            ['Sushi', 'CT', 'M. Da Costa', '076 118 39 90', 'Vomissements récurrents le matin', 'Le matin de préférence', $ligneMeyer->id, 30, $jour->setTime(8, 12)->utc()],
            ['Praline', 'LP', 'Mme Guex', '078 455 71 03', 'Contrôle dents + griffes', 'Cette semaine', null, 20, $jour->setTime(9, 5)->utc()],
        ];
        foreach ($listeAttente as [$nom, $espece, $proprietaire, $tel, $motif, $preference, $ligneId, $duree, $inscrit]) {
            ListeAttente::create([
                'clinique_id' => $clinique->id,
                'patient_name' => $nom,
                'species' => Espece::from($espece),
                'owner_name' => $proprietaire,
                'tel' => $tel,
                'reason' => $motif,
                'preference' => $preference,
                'ligne_planning_id' => $ligneId,
                'duree_minutes' => $duree,
                'inscrit_le' => $inscrit,
            ]);
        }
    }
}
