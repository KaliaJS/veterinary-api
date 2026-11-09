<?php

namespace App\Services\Referrals;

use App\Enums\StatutEnregistrement;
use App\Models\EnregistrementConsultation;
use App\Models\Patient;
use App\Models\ReferralLetter;
use App\Services\Patients\RecordShareItems;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Les sources de la lettre de référence, numérotées (09.11.2026) : P, chaque
 * rubrique (ou tableau de valeurs) des pièces jointes du dossier, signées ou
 * validées ; N, chaque ligne non vide des notes du vétérinaire (motif et
 * question) ; D, chaque passage des dictées transcrites, dans l'ordre.
 * L'IA ne cite que ces numéros.
 */
final class ReferralLetterSources
{
    public function __construct(private RecordShareItems $items) {}

    /** @return list<array{id: string, kind: string, label: string, text: string, recording_id?: int}> */
    public function for(ReferralLetter $letter): array
    {
        $patient = Patient::withoutGlobalScopes()->findOrFail($letter->patient_id);
        $sources = [];
        $p = 0;
        foreach ($letter->items ?? [] as $item) {
            $printable = $this->items->printable($patient, (string) $item['type'], (int) $item['id'], ['visit_notes' => $letter->include_visit_notes]);
            if ($printable === null || $printable['type'] === 'document' || $printable['type'] === 'referral_letter') {
                continue;
            }
            $what = $printable['title'].', '.self::day($printable['date']);
            foreach ($printable['sections'] as $section) {
                $sources[] = ['id' => 'P'.(++$p), 'kind' => 'piece', 'label' => $what.', '.mb_strtolower($section['label']), 'text' => preg_replace('/\R+/', ' ', trim($section['text']))];
            }
            if (! empty($printable['table']['rows'])) {
                $columns = $printable['table']['columns'];
                $rows = array_map(fn (array $row) => $row[0].' : '.implode(' ', array_filter(array_slice($row, 1), fn ($v) => trim((string) $v) !== '')), $printable['table']['rows']);
                $sources[] = ['id' => 'P'.(++$p), 'kind' => 'piece', 'label' => $what.', '.mb_strtolower((string) ($columns[0] ?? 'valeurs')), 'text' => implode(' ; ', $rows)];
            }
        }

        $n = 0;
        foreach (preg_split('/\R/', (string) $letter->notes) ?: [] as $line) {
            if (trim($line) !== '') {
                $sources[] = ['id' => 'N'.(++$n), 'kind' => 'note', 'label' => 'Note '.$n, 'text' => trim($line)];
            }
        }

        $d = 0;
        $recordings = self::recordings($letter->id);
        $isMany = $recordings->count() > 1;
        foreach ($recordings->values() as $index => $recording) {
            $isManyParts = $recording->parties->count() > 1;
            foreach ($recording->parties as $part) {
                foreach ($part->segments ?? [] as $segment) {
                    $text = trim((string) ($segment['texte'] ?? ''));
                    if ($text === '') {
                        continue;
                    }
                    $seconds = (int) ($segment['debut'] ?? 0);
                    $prefix = ($isMany ? 'Dictée '.($index + 1).', ' : 'Dictée ').($isManyParts ? 'partie '.$part->numero.', ' : '');
                    $sources[] = ['id' => 'D'.(++$d), 'kind' => 'dictation', 'label' => $prefix.sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60), 'text' => $text, 'recording_id' => $recording->id];
                }
            }
        }

        return $sources;
    }

    private static function day(string $date): string
    {
        return $date === '' ? '' : CarbonImmutable::parse($date)->format('d.m.Y');
    }

    /**
     * Les dictées transcrites d'une lettre de référence, dans l'ordre
     *
     * @return Collection<int, EnregistrementConsultation>
     */
    public static function recordings(int $letterId): Collection
    {
        return EnregistrementConsultation::withoutGlobalScopes()
            ->where('referral_letter_id', $letterId)
            ->whereIn('statut', [StatutEnregistrement::Pret->value, StatutEnregistrement::Valide->value])
            ->orderBy('id')
            ->with('parties')
            ->get();
    }
}
