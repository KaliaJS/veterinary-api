<?php

namespace App\Services\Hospitalisation;

use App\Enums\StatutEnregistrement;
use App\Models\EnregistrementConsultation;
use App\Models\Intervention;
use App\Models\Sejour;
use App\Models\StaySheet;
use App\Models\StayTreatment;
use App\Models\SurgeryReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Les sources de la lettre de sortie, numérotées (09.11.2026) : S, le
 * séjour (l'animal, le motif, les dates, le vétérinaire) ; T, chaque
 * traitement donné pendant le séjour ; R, chaque rapport de la journée
 * signé ; B, chaque rubrique d'un rapport de bloc validé pendant le séjour ;
 * N, chaque ligne non vide des consignes tapées ; D, chaque passage des
 * dictées transcrites, dans l'ordre. L'IA ne cite que ces numéros.
 */
final class DischargeLetterSources
{
    public function __construct(private StaySheets $sheets) {}

    /** @return list<array{id: string, kind: string, label: string, text: string, recording_id?: int}> */
    public function for(Sejour $sejour): array
    {
        $sejour->loadMissing(['patient', 'veterinaire' => fn ($q) => $q->withoutGlobalScope('clinique'), 'clinique', 'dischargeLetter']);
        $sources = [['id' => 'S1', 'kind' => 'stay', 'label' => 'Séjour', 'text' => $this->stay($sejour)]];

        $t = 0;
        // Les traitements commencés pendant le séjour : pas un traitement prévu après une sortie anticipée
        foreach ($sejour->treatments()->when($sejour->sorti_le !== null, fn ($q) => $q->where('starts_at', '<=', $sejour->sorti_le))->get() as $treatment) {
            $sources[] = ['id' => 'T'.(++$t), 'kind' => 'treatment', 'label' => 'Traitement', 'text' => $this->treatment($sejour, $treatment)];
        }

        $r = 0;
        foreach (StaySheet::query()->where('sejour_id', $sejour->id)->where('report_status', StaySheet::REPORT_SIGNED)->orderBy('day')->get() as $sheet) {
            if (trim((string) $sheet->report_text) === '') {
                continue;
            }
            $sources[] = ['id' => 'R'.(++$r), 'kind' => 'report', 'label' => 'Rapport du '.$this->day($sheet->day->toDateString()), 'text' => trim((string) $sheet->report_text)];
        }

        $b = 0;
        foreach ($this->surgeries($sejour) as $intervention) {
            foreach (SurgeryReport::normalizeSections($intervention->report?->sections) as $key => $text) {
                if ($text !== '') {
                    $sources[] = ['id' => 'B'.(++$b), 'kind' => 'surgery', 'label' => 'Rapport de bloc, '.mb_strtolower(SurgeryReport::SECTION_LABELS[$key]), 'text' => $intervention->procedure.' : '.preg_replace('/\R+/', ' ', $text)];
                }
            }
        }

        $n = 0;
        foreach (preg_split('/\R/', (string) $sejour->dischargeLetter?->home_notes) ?: [] as $line) {
            if (trim($line) !== '') {
                $sources[] = ['id' => 'N'.(++$n), 'kind' => 'note', 'label' => 'Consigne '.$n, 'text' => trim($line)];
            }
        }

        $d = 0;
        foreach ($this->dictation($sejour) as $segment) {
            $sources[] = ['id' => 'D'.(++$d), 'kind' => 'dictation', 'label' => $segment['label'], 'text' => $segment['text'], 'recording_id' => $segment['recording_id']];
        }

        return $sources;
    }

    /** Le jour d'une date, en toutes lettres : « jeudi 5 novembre » */
    public function day(string $date): string
    {
        return CarbonImmutable::parse($date)->locale('fr_CH')->isoFormat('dddd D MMMM');
    }

    private function stay(Sejour $sejour): string
    {
        $patient = $sejour->patient;
        $tz = $this->sheets->timezone($sejour);
        $local = fn (\DateTimeInterface $at) => CarbonImmutable::instance($at)->setTimezone($tz)->toDateString();

        return implode(' · ', array_filter([
            $patient ? trim(implode(', ', array_filter([$patient->nom, $patient->espece->libelle(), $patient->race]))) : null,
            $sejour->motif ? 'Motif : '.$sejour->motif : null,
            'Admis le '.$this->day($local($sejour->admis_le)),
            $sejour->sorti_le !== null
                ? 'Sorti le '.$this->day($local($sejour->sorti_le))
                : (filled($sejour->sortie_prevue) ? 'Sortie prévue : '.$sejour->sortie_prevue : null),
            $sejour->veterinaire?->nom ? 'Vétérinaire : '.$sejour->veterinaire->nom : null,
        ]));
    }

    private function treatment(Sejour $sejour, StayTreatment $treatment): string
    {
        $tz = $this->sheets->timezone($sejour);
        $date = fn (?\DateTimeInterface $at) => $at === null ? null : CarbonImmutable::instance($at)->setTimezone($tz)->locale('fr_CH')->isoFormat('D MMMM');
        $from = $date($treatment->starts_at);
        $end = $treatment->ends_at ?? $sejour->sorti_le;
        $to = $date($end);
        $when = match (true) {
            $from === null => null,
            $to === null => 'depuis le '.$from,
            $from === $to => 'le '.$from,
            default => 'du '.$from.' au '.$to,
        };

        return implode(', ', array_filter([trim((string) $treatment->label), trim((string) $treatment->dose), $when]));
    }

    /**
     * Les interventions de l'animal faites pendant le séjour (le jour de
     * l'admission compris) dont le rapport de bloc est validé
     *
     * @return Collection<int, Intervention>
     */
    private function surgeries(Sejour $sejour): Collection
    {
        $tz = $this->sheets->timezone($sejour);
        $from = CarbonImmutable::instance($sejour->admis_le)->setTimezone($tz)->startOfDay()->utc();
        $to = CarbonImmutable::instance($sejour->sorti_le ?? now());

        return Intervention::withoutGlobalScopes()
            ->where('patient_id', $sejour->patient_id)
            ->where('clinique_id', $sejour->clinique_id)
            ->whereBetween('prevu_le', [$from, $to])
            ->whereHas('report', fn ($q) => $q->where('status', 'validated'))
            ->with('report')
            ->orderBy('prevu_le')
            ->get();
    }

    /**
     * Les passages des dictées transcrites des consignes, dans l'ordre
     *
     * @return list<array{label: string, text: string, recording_id: int}>
     */
    private function dictation(Sejour $sejour): array
    {
        $recordings = self::recordings($sejour->id);
        $isManyRecordings = $recordings->count() > 1;

        $out = [];
        foreach ($recordings->values() as $index => $recording) {
            $isManyParts = $recording->parties->count() > 1;
            foreach ($recording->parties as $part) {
                foreach ($part->segments ?? [] as $segment) {
                    $text = trim((string) ($segment['texte'] ?? ''));
                    if ($text === '') {
                        continue;
                    }
                    $seconds = (int) ($segment['debut'] ?? 0);
                    $prefix = ($isManyRecordings ? 'Dictée '.($index + 1).', ' : 'Dictée ').($isManyParts ? 'partie '.$part->numero.', ' : '');
                    $out[] = ['label' => ucfirst($prefix.sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60)), 'text' => $text, 'recording_id' => $recording->id];
                }
            }
        }

        return $out;
    }

    /**
     * Les dictées transcrites des consignes d'un séjour, dans l'ordre
     *
     * @return Collection<int, EnregistrementConsultation>
     */
    public static function recordings(int $sejourId): Collection
    {
        return EnregistrementConsultation::withoutGlobalScopes()
            ->where('sejour_id', $sejourId)
            ->whereIn('statut', [StatutEnregistrement::Pret->value, StatutEnregistrement::Valide->value])
            ->orderBy('id')
            ->with('parties')
            ->get();
    }
}
