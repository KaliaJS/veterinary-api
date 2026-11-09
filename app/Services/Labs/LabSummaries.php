<?php

namespace App\Services\Labs;

use App\Jobs\WriteLabSummary;
use App\Models\Analyse;
use App\Models\LabSummary;
use App\Services\Bloc\SurgeryReportDrafts;
use App\Services\BudgetAnthropic;
use App\Services\CompteurMensuel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Le résumé d'une analyse (09.11.2026) : la demande du brouillon (bornée
 * par le plafond de rédactions et le budget de l'IA de la plateforme), le
 * nettoyage de la réponse (une phrase ne garde que les sources qui
 * existent ; sans source, elle est retirée), la fusion qui garde les
 * parties corrigées, et le texte envoyé au propriétaire.
 */
final class LabSummaries
{
    /** Le résumé de l'analyse, créé vide au premier regard */
    public function of(Analyse $analyse): LabSummary
    {
        // Deux premiers gestes en même temps : un seul résumé, l'autre le relit
        try {
            return LabSummary::query()->firstOrCreate(['analyse_id' => $analyse->id], [
                'organisation_id' => $analyse->clinique->societe->organisation_id ?? 0,
                'clinique_id' => $analyse->clinique_id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return LabSummary::query()->where('analyse_id', $analyse->id)->firstOrFail();
        }
    }

    /** Demande un brouillon ; rend la raison d'un refus ('signed', 'writing', 'writings', 'budget'), ou null */
    public function request(LabSummary $summary): ?string
    {
        $refusal = DB::transaction(function () use ($summary): ?string {
            $locked = LabSummary::withoutGlobalScopes()->whereKey($summary->id)->lockForUpdate()->firstOrFail();
            $refusal = match (true) {
                $locked->isSigned() => 'signed',
                $locked->status === 'writing' => 'writing',
                $locked->writings >= (int) config('transcription.redactions_max') => 'writings',
                app(BudgetAnthropic::class)->atteint() => 'budget',
                default => null,
            };
            if ($refusal === 'writings' || $refusal === 'budget') {
                $locked->forceFill(['error' => $refusal])->save();
            }
            if ($refusal !== null) {
                return $refusal;
            }
            $locked->forceFill(['status' => 'writing', 'error' => null, 'writings' => $locked->writings + 1])->save();

            return null;
        });
        if ($refusal !== null) {
            return $refusal;
        }
        CompteurMensuel::ajouter('anthropic:redactions', 1);
        try {
            WriteLabSummary::dispatch($summary->id);
        } catch (Throwable $error) {
            LabSummary::withoutGlobalScopes()->whereKey($summary->id)->where('status', 'writing')->update(['status' => 'failed', 'error' => 'writer']);

            throw $error;
        }
        LabSummary::announce($summary->analyse_id, $summary->clinique_id);

        return null;
    }

    /**
     * @param  array{sections?: mixed}  $raw
     * @param  list<array{id: string, kind: string, label: string, text: string}>  $sources
     * @return array{sources: list<array{id: string, kind: string, label: string, text: string}>, sections: array<string, list<array{text: string, sources: list<string>}>>}
     */
    public function clean(array $raw, array $sources): array
    {
        $known = array_column($sources, 'id');
        $sections = [];
        foreach (LabSummary::SECTIONS as $key) {
            $sections[$key] = collect(is_array($raw['sections'][$key] ?? null) ? $raw['sections'][$key] : [])
                ->map(fn ($line) => [
                    'text' => SurgeryReportDrafts::undash(trim((string) ($line['text'] ?? ''))),
                    'sources' => array_values(array_intersect(array_map('strval', (array) ($line['sources'] ?? [])), $known)),
                ])
                ->filter(fn (array $line) => $line['text'] !== '' && $line['sources'] !== [])
                ->values()
                ->all();
        }

        return ['sources' => $sources, 'sections' => $sections];
    }

    /**
     * Les parties après un nouveau brouillon : une partie corrigée garde son texte
     *
     * @param  array<string, mixed>|null  $current
     * @param  list<string>|null  $edited
     * @param  array<string, list<array{text: string, sources: list<string>}>>  $new
     * @return array<string, string>
     */
    public static function merge(?array $current, ?array $edited, array $new): array
    {
        $current = LabSummary::normalizeSections($current);
        $out = [];
        foreach (LabSummary::SECTIONS as $key) {
            $out[$key] = in_array($key, $edited ?? [], true) ? $current[$key] : SurgeryReportDrafts::text($new[$key] ?? []);
        }

        return $out;
    }
}
