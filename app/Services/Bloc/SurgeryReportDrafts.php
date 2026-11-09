<?php

namespace App\Services\Bloc;

use App\Jobs\WriteSurgeryReport;
use App\Models\Intervention;
use App\Models\SurgeryReport;
use App\Services\BudgetAnthropic;
use App\Services\CompteurMensuel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Le brouillon du rapport de bloc (09.11.2026) : la demande (bornée par le
 * plafond de rédactions et le budget de l'IA de la plateforme, comme les
 * comptes rendus), puis le nettoyage de la réponse du rédacteur. Une phrase
 * ne garde que les sources qui existent ; sans source, elle est retirée.
 * Un doute ne reste que si son passage est dans sa rubrique (ou s'il n'en
 * a pas). Jamais de tiret de ponctuation (règle de Jim).
 */
final class SurgeryReportDrafts
{
    /**
     * Demande un brouillon ; rend la raison d'un refus ('validated',
     * 'writing', 'writings', 'budget'), ou null quand la rédaction part.
     */
    public function request(Intervention $intervention, bool $isDictation = false, int $delaySeconds = 0): ?string
    {
        $refusal = DB::transaction(function () use ($intervention, $isDictation): ?string {
            self::ensure($intervention);
            $report = SurgeryReport::query()->where('intervention_id', $intervention->id)->lockForUpdate()->firstOrFail();
            $refusal = match (true) {
                $report->isValidated() => 'validated',
                $report->status === 'writing' => 'writing',
                $report->writings >= (int) config('transcription.redactions_max') => 'writings',
                app(BudgetAnthropic::class)->atteint() => 'budget',
                default => null,
            };
            if ($refusal === 'writings' || $refusal === 'budget') {
                $report->forceFill(['error' => $refusal])->save();
            }
            // Une dictée prête pendant une rédaction : un nouveau brouillon suit celle en cours (2e vérification)
            if ($refusal === 'writing' && $isDictation) {
                $report->forceFill(['is_redraft_pending' => true])->save();
            }
            if ($refusal !== null) {
                return $refusal;
            }
            // Une rédaction qui part prend aussi ce qu'une dictée en attente apportait
            $report->forceFill(['status' => 'writing', 'error' => null, 'writings' => $report->writings + 1, 'is_redraft_pending' => false])->save();

            return null;
        });
        if ($refusal !== null) {
            return $refusal;
        }

        // Comptée au budget du mois où elle part, comme les comptes rendus ;
        // la tâche part avant l'annonce (une annonce ne bloque jamais rien)
        CompteurMensuel::ajouter('anthropic:redactions', 1);
        WriteSurgeryReport::dispatch($intervention->id)->delay($delaySeconds > 0 ? now()->addSeconds($delaySeconds) : null);
        SurgeryReport::announce($intervention);

        return null;
    }

    /** Le rapport existe (deux premières demandes en même temps : une seule ligne) */
    public static function ensure(Intervention $intervention): void
    {
        try {
            SurgeryReport::query()->firstOrCreate(['intervention_id' => $intervention->id]);
        } catch (UniqueConstraintViolationException) {
            // Créé à l'instant par une autre requête
        }
    }

    /**
     * Les rubriques après un nouveau brouillon (1re et 2e vérifications) :
     * une rubrique corrigée par le vétérinaire garde son texte, même vidée
     * exprès ; seules celles qu'il n'a jamais touchées prennent le nouveau
     * texte (la fenêtre propose le nouveau texte sous une rubrique gardée).
     *
     * @param  array<string, mixed>|null  $current
     * @param  list<string>|null  $edited
     * @param  array<string, list<array{text: string, sources: list<string>}>>  $newSections
     * @return array<string, string>
     */
    public static function merge(?array $current, ?array $edited, array $newSections): array
    {
        $current = SurgeryReport::normalizeSections($current);
        $out = [];
        foreach (SurgeryReport::SECTIONS as $key) {
            $out[$key] = in_array($key, $edited ?? [], true) ? $current[$key] : self::text($newSections[$key] ?? []);
        }

        return $out;
    }

    /**
     * @param  array{sections?: mixed, doubts?: mixed}  $raw
     * @param  list<array{id: string, kind: string, label: string, text: string}>  $sources
     * @return array{sources: list<array{id: string, kind: string, label: string, text: string}>, sections: array<string, list<array{text: string, sources: list<string>}>>, doubts: list<array{section: string, passage: string, heard: string, reason: string}>}
     */
    public function clean(array $raw, array $sources): array
    {
        $known = array_column($sources, 'id');
        $sections = [];
        foreach (SurgeryReport::SECTIONS as $key) {
            $sections[$key] = collect(is_array($raw['sections'][$key] ?? null) ? $raw['sections'][$key] : [])
                ->map(fn ($line) => [
                    'text' => self::undash(trim((string) ($line['text'] ?? ''))),
                    'sources' => array_values(array_intersect(array_map('strval', (array) ($line['sources'] ?? [])), $known)),
                ])
                ->filter(fn (array $line) => $line['text'] !== '' && $line['sources'] !== [])
                ->values()
                ->all();
        }

        $doubts = collect(is_array($raw['doubts'] ?? null) ? $raw['doubts'] : [])
            ->map(fn ($d) => [
                'section' => (string) ($d['section'] ?? ''),
                'passage' => trim((string) ($d['passage'] ?? '')),
                'heard' => trim((string) ($d['heard'] ?? '')),
                'reason' => self::undash(trim((string) ($d['reason'] ?? ''))),
            ])
            ->filter(function (array $d) use ($sections): bool {
                if (! array_key_exists($d['section'], $sections) || $d['reason'] === '') {
                    return false;
                }

                return $d['passage'] === '' || str_contains(self::text($sections[$d['section']]), $d['passage']);
            })
            ->values()
            ->all();

        return ['sources' => $sources, 'sections' => $sections, 'doubts' => $doubts];
    }

    /**
     * Le texte à relire d'une rubrique : ses phrases, une par ligne
     *
     * @param  list<array{text: string, sources: list<string>}>  $lines
     */
    public static function text(array $lines): string
    {
        return implode("\n", array_column($lines, 'text'));
    }

    /** Un tiret entre deux nombres est un intervalle (« 1,5–2 % » : « 1,5 à 2 % ») ; ailleurs, une virgule */
    public static function undash(string $text): string
    {
        // Une taille de fil (« 3–0 ») reste une taille : « 3-0 »
        $text = (string) preg_replace('/\b(\d{1,2})\s*[–—]\s*0\b/u', '$1-0', $text);
        $text = (string) preg_replace('/(\d)\s*[–—]\s*(\d)/u', '$1 à $2', $text);

        return str_replace([' — ', ' – ', '—', '–'], [', ', ', ', ', ', ', '], $text);
    }
}
