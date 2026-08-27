<?php

namespace Database\Seeders;

use App\Enums\ModaliteImagerie;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\ExamenImagerie;
use App\Models\Organisation;
use App\Models\Patient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Imagerie du front (imaging.js — buildStudies) : radios issues des
 * clichés des fiches patients, une poignée d'échos et d'endoscopies,
 * trois examens récents « à interpréter », comptes rendus types
 * signés. Sélection déterministe sans parité RNG — comme le bloc, le
 * raccord fin se fera à la bascule. Chaque radio reçoit un cliché
 * DICOM de métadonnées (graine de la visionneuse procédurale).
 */
class ImagerieSeeder extends Seeder
{
    use WithoutModelEvents;

    private const array RADIOS = [
        ['Radiographie thorax', 'Profil droit · Face'],
        ['Radiographie abdomen', 'Face (VD) · Profil'],
        ['Radiographie membre', 'Face + profil'],
    ];

    /** Comptes rendus types du front (REP) — constatations, conclusion. */
    private const array COMPTES_RENDUS = [
        'DX' => [
            ['Cliché de qualité diagnostique. Silhouette cardiaque dans les limites (VHS 10,3). Champs pulmonaires clairs, vascularisation régulière. Coupoles diaphragmatiques nettes.', 'Examen dans les limites de la normale.'],
            ['Opacité alvéolaire en lobe moyen droit avec bronchogramme aérique. Silhouette cardiaque non déformée. Pas d\'épanchement pleural.', 'Image compatible avec une bronchopneumonie — corrélation clinique et cliché de contrôle à 10 jours.'],
            ['Discrète spondylose L2–L4 sans lyse ni réaction périostée agressive. Interlignes articulaires conservés. Tissus mous sans particularité.', 'Remaniements dégénératifs modérés, sans signe de gravité.'],
        ],
        'US' => [
            ['Foie de taille et d\'échogénicité normales. Vésicule biliaire anéchogène à paroi fine. Reins symétriques, différenciation cortico-médullaire conservée. Vessie à contenu anéchogène.', 'Échographie abdominale sans anomalie significative.'],
            ['Paroi gastrique discrètement épaissie (5,1 mm) à stratification conservée. Ganglions jéjunaux à la limite supérieure. Reste de l\'examen sans particularité.', 'Aspect compatible avec une gastrite — cytologie ou suivi échographique conseillé.'],
        ],
        'ES' => [
            ['Œsophage — muqueuse rosée, lisse, jonction cardiale continente. Estomac — lac muqueux clair, plis souples, pas d\'ulcération visible. Pylore franchissable.', 'Endoscopie digestive haute macroscopiquement normale — biopsies étagées en attente d\'histologie.'],
            ['Muqueuse gastrique érythémateuse en antre avec érosions punctiformes. Duodénum proximal sans particularité. Biopsies réalisées (×6).', 'Gastrite érosive antrale — traitement gastro-protecteur initié dans l\'attente de l\'histologie.'],
        ],
    ];

    public function run(): void
    {
        $organisation = Organisation::where('nom', 'Deux Rives Vétérinaires')->firstOrFail();
        $deuxRives = Clinique::where('nom', 'Deux Rives')->firstOrFail();

        $veterinaires = [
            Employe::where('nom', 'Dr Anna Meyer')->firstOrFail(),
            Employe::where('nom', 'Dr Rachel Solal')->firstOrFail(),
            Employe::where('nom', 'Dr Kofi Adjei')->firstOrFail(),
        ];

        $patients = Patient::where('organisation_id', $organisation->id)->orderBy('id')->get();
        $aujourdhui = CarbonImmutable::today('Europe/Zurich');
        $numeroExamen = 0;
        $echosEtEndos = 0;

        foreach ($patients as $i => $patient) {
            // Environ un patient sur deux a une radio au dossier.
            if ($i % 2 === 0) {
                [$libelle, $incidence] = self::RADIOS[$i % 3];

                $this->creerExamen(
                    $numeroExamen++,
                    $deuxRives->id,
                    $patient,
                    ModaliteImagerie::Radiographie,
                    $libelle,
                    $incidence,
                    $aujourdhui->subDays(12 + ($i * 37) % 460),
                    $veterinaires,
                );
            }

            // Une poignée d'échos et d'endoscopies.
            if ($echosEtEndos < 14 && $i % 9 === 1) {
                $echosEtEndos++;
                $this->creerExamen(
                    $numeroExamen++,
                    $deuxRives->id,
                    $patient,
                    $echosEtEndos % 3 === 0 ? ModaliteImagerie::Endoscopie : ModaliteImagerie::Echographie,
                    $echosEtEndos % 3 === 0 ? 'Endoscopie digestive haute' : 'Échographie abdominale',
                    $echosEtEndos % 3 === 0 ? 'Vidéo-endoscope Ø 7,9 mm' : 'Sonde micro-convexe 8 MHz',
                    $aujourdhui->subDays(1 + ($i * 23) % 220),
                    $veterinaires,
                );
            }
        }

        // Trois examens récents forcés « à interpréter » (parité front).
        foreach (ExamenImagerie::where('clinique_id', $deuxRives->id)->orderBy('id')->limit(21)->get() as $k => $examen) {
            if ($k % 7 !== 0) {
                continue;
            }

            $examen->update([
                'realise_le' => $aujourdhui->subDays(intdiv($k, 7))->setTime(9 + $k % 8, 30)->utc(),
                'constatations' => null,
                'conclusion' => null,
                'signe_par_id' => null,
                'signe_le' => null,
            ]);
        }
    }

    /** @param list<Employe> $veterinaires */
    private function creerExamen(
        int $numero,
        int $cliniqueId,
        Patient $patient,
        ModaliteImagerie $modalite,
        string $libelle,
        ?string $incidence,
        CarbonImmutable $jour,
        array $veterinaires,
    ): void {
        // Un peu plus d'un examen sur deux a son compte rendu signé.
        $signe = ($numero * 7) % 10 < 6;
        $comptesRendus = self::COMPTES_RENDUS[$modalite->value];
        [$constatations, $conclusion] = $comptesRendus[$numero % count($comptesRendus)];

        $examen = ExamenImagerie::create([
            'clinique_id' => $cliniqueId,
            'patient_id' => $patient->id,
            'modalite' => $modalite,
            'libelle' => $libelle,
            'incidence' => $incidence,
            'realise_le' => $jour->setTime(8 + $numero % 9, ($numero * 20) % 60)->utc(),
            'duree_minutes' => $modalite === ModaliteImagerie::Radiographie ? 30 : 40,
            'prescripteur_id' => $veterinaires[$numero % 3]->id,
            ...$signe ? [
                'constatations' => $constatations,
                'conclusion' => $conclusion,
                'signe_par_id' => $veterinaires[$numero % 2]->id,
                'signe_le' => $jour->setTime(17, 0)->utc(),
            ] : [],
        ]);

        if ($modalite === ModaliteImagerie::Radiographie) {
            $examen->cliches()->create([
                'sop_instance_uid' => sprintf('1.2.756.777.%d.%d.1', $patient->id, $examen->id),
                'study_instance_uid' => sprintf('1.2.756.777.%d.%d', $patient->id, $examen->id),
                'nom_fichier' => sprintf('%s_%d.dcm', str_replace(' ', '_', mb_strtolower($libelle)), $examen->id),
                'metadonnees' => ['graine' => ($patient->id * 48271 + $examen->id * 16807) % 1000000000],
            ]);
        }
    }
}
