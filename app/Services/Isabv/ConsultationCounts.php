<?php

namespace App\Services\Isabv;

use App\Enums\Espece;
use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Models\Clinique;
use App\Models\IsabvConsultationReport;
use App\Models\Patient;
use App\Models\RendezVous;
use Carbon\CarbonImmutable;

/**
 * Le nombre de consultations par espèce et par année (O-SI ABV, annexe
 * ch. 2.2.1.5), pour les animaux de compagnie et les équidés (08.11.2026).
 * Une consultation est une visite chez le vétérinaire avec un traitement ou
 * un examen, antibiotiques ou non : une inscription à l'agenda (directives,
 * ch. 2.5). Comptés : les rendez-vous finis de la clinique dans l'année,
 * dans son fuseau, sauf les plages tampon et les téléconsultations. Quatre espèces : chiens, chats,
 * équidés, autres (directives, ch. 5.1.5) ; un animal de rente n'y entre
 * pas.
 */
class ConsultationCounts
{
    /** Les espèces du nombre de consultations, codes de l'OSAV */
    public const SPECIES = ['1.5' => 'Chiens', '1.4' => 'Chats', '1.1' => 'Équidés', '1.11' => 'Autres'];

    /** @return array<string, int> code de l'espèce => nombre */
    public function count(Clinique $clinique, int $year): array
    {
        $timezone = $clinique->fuseau_horaire ?: 'Europe/Zurich';
        $start = CarbonImmutable::create($year, 1, 1, 0, 0, 0, $timezone)->utc();
        $end = $start->setTimezone($timezone)->addYear()->utc();
        $counts = array_fill_keys(array_keys(self::SPECIES), 0);
        RendezVous::query()->withoutGlobalScopes()
            ->where('rendez_vous.clinique_id', $clinique->id)
            ->where('rendez_vous.status', StatutRendezVous::Fini)
            // Une visite chez le vétérinaire : ni plage tampon, ni téléconsultation (directives, ch. 2.5)
            ->whereNotIn('rendez_vous.type', [TypeRendezVous::Tampon, TypeRendezVous::Teleconsultation])
            ->where('rendez_vous.starts_at', '>=', $start)->where('rendez_vous.starts_at', '<', $end)
            ->leftJoin('patients', 'patients.id', '=', 'rendez_vous.patient_id')
            ->selectRaw('rendez_vous.species as species, patients.espece as espece, patients.isabv_species as isabv_species, count(*) as n')
            ->groupBy('rendez_vous.species', 'patients.espece', 'patients.isabv_species')
            ->toBase()
            ->get()
            ->each(function ($row) use (&$counts): void {
                $code = self::speciesOf($row->isabv_species, $row->espece ?? $row->species);
                if ($code !== null) {
                    $counts[$code] += (int) $row->n;
                }
            });

        return $counts;
    }

    /**
     * Envoie le nombre de chaque espèce pour une année ; un nouvel envoi
     * remplace le précédent à l'OSAV. Rend les nombres envoyés.
     *
     * @return array<string, int>
     */
    public function send(Clinique $clinique, int $year, IsabvClient $client): array
    {
        $counts = $this->count($clinique, $year);
        foreach ($counts as $species => $count) {
            $result = $client->saveConsultation($year, $species, $count);
            IsabvConsultationReport::query()->withoutGlobalScopes()->updateOrCreate(
                ['clinique_id' => $clinique->id, 'year' => $year, 'species_code' => $species],
                ['count' => $count, 'status' => $result->isOk ? 'sent' : 'rejected', 'osav_message' => $result->isOk ? null : $result->message, 'sent_at' => now()],
            );
        }

        return $counts;
    }

    /** L'espèce comptée : celle de la fiche au sens de l'OSAV, sinon d'après l'espèce ; null pour un animal de rente */
    public static function speciesOf(?string $isabvSpecies, mixed $espece): ?string
    {
        $isabvSpecies = trim((string) $isabvSpecies);
        if ($isabvSpecies !== '') {
            return array_key_exists($isabvSpecies, self::SPECIES) ? $isabvSpecies : null;
        }
        $espece = $espece instanceof Espece ? $espece : Espece::tryFrom((string) $espece);
        if ($espece === null) {
            return null;
        }
        $patient = new Patient(['espece' => $espece]);
        $default = Declarations::defaultSpecies($patient);

        return $default !== null && array_key_exists($default, self::SPECIES) ? $default : null;
    }

    /**
     * Ce qui est compté et ce qui est parti, pour l'année en cours et la
     * précédente (les seules que l'OSAV accepte, directives ch. 5.1.5).
     *
     * @return list<array<string, mixed>>
     */
    public function overview(Clinique $clinique): array
    {
        $current = (int) CarbonImmutable::now($clinique->fuseau_horaire ?: 'Europe/Zurich')->year;
        $years = [];
        foreach ([$current - 1, $current] as $year) {
            $reports = IsabvConsultationReport::query()->withoutGlobalScopes()->where('clinique_id', $clinique->id)->where('year', $year)->get()->keyBy('species_code');
            $counts = $this->count($clinique, $year);
            $years[] = [
                'year' => $year,
                'deadline' => ($year + 1).'-02-20',
                'species' => array_map(fn (string $code) => [
                    'code' => $code,
                    'label' => self::SPECIES[$code],
                    'count' => $counts[$code],
                    'sent_count' => $reports->get($code)?->count,
                    'status' => $reports->get($code)?->status,
                    'message' => $reports->get($code)?->osav_message,
                    'sent_at' => $reports->get($code)?->sent_at?->toIso8601String(),
                ], array_keys(self::SPECIES)),
            ];
        }

        return $years;
    }
}
