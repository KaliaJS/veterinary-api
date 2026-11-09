<?php

namespace App\Jobs;

use App\Models\Analyse;
use App\Models\LabSummary;
use App\Services\BudgetAnthropic;
use App\Services\Labs\LabSummaries;
use App\Services\Labs\LabSummaryImpossible;
use App\Services\Labs\LabSummarySources;
use App\Services\Labs\LabSummaryWriter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Rédige le brouillon du résumé d'une analyse (09.11.2026) depuis ses
 * sources numérotées (valeurs, évolutions calculées). Un résumé signé
 * entre-temps n'est jamais touché.
 */
final class WriteLabSummary implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 300;

    public function __construct(public int $summaryId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('lab-summary:'.$this->summaryId))->releaseAfter(60)->expireAfter($this->timeout + 100)];
    }

    public function handle(LabSummaryWriter $writer, LabSummarySources $sources, LabSummaries $summaries): void
    {
        $summary = LabSummary::withoutGlobalScopes()->find($this->summaryId);
        if ($summary?->status !== 'writing') {
            return;
        }
        $analyse = Analyse::withoutGlobalScopes()->with(['patient' => fn ($q) => $q->withoutGlobalScopes(), 'clinique' => fn ($q) => $q->withoutGlobalScopes()])->find($summary->analyse_id);
        if ($analyse === null) {
            $this->markFailed();

            return;
        }
        $list = $sources->for($analyse);
        try {
            if ($this->attempts() > 1) {
                BudgetAnthropic::nouvelEssai('redaction');
            }
            $draft = $summaries->clean($writer->write($list, array_filter([
                'animal' => trim(($analyse->patient->nom ?? '').', '.($analyse->patient?->espece?->libelle() ?? ''), ', '),
                'prélevé le' => LabSummarySources::day(CarbonImmutable::parse($analyse->preleve_le), $analyse->clinique->fuseau_horaire ?? 'Europe/Zurich'),
            ])), $list);
        } catch (LabSummaryImpossible $error) {
            report($error);
            $this->markFailed();

            return;
        }

        DB::transaction(function () use ($draft): void {
            $locked = LabSummary::withoutGlobalScopes()->whereKey($this->summaryId)->lockForUpdate()->first();
            if ($locked?->status !== 'writing') {
                return;
            }
            $locked->forceFill([
                'status' => 'draft',
                'error' => null,
                'sections' => LabSummaries::merge($locked->sections, $locked->edited_sections, $draft['sections']),
                'draft' => $draft,
                'written_at' => now(),
            ])->save();
        });
        LabSummary::announce($summary->analyse_id, $summary->clinique_id);
    }

    public function failed(?Throwable $error): void
    {
        $this->markFailed();
    }

    private function markFailed(): void
    {
        $summary = LabSummary::withoutGlobalScopes()->find($this->summaryId);
        if ($summary !== null && LabSummary::withoutGlobalScopes()->whereKey($summary->id)->where('status', 'writing')->update(['status' => 'failed', 'error' => 'writer']) === 1) {
            LabSummary::announce($summary->analyse_id, $summary->clinique_id);
        }
    }
}
