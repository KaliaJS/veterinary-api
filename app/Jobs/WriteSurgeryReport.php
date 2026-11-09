<?php

namespace App\Jobs;

use App\Models\Intervention;
use App\Models\SurgeryReport;
use App\Services\Bloc\SurgeryReportDrafts;
use App\Services\Bloc\SurgeryReportImpossible;
use App\Services\Bloc\SurgeryReportSources;
use App\Services\Bloc\SurgeryReportWriter;
use App\Services\BudgetAnthropic;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Rédige le brouillon du rapport de bloc (09.11.2026) depuis les sources
 * numérotées (écran, notes, dictée). Le brouillon attend la relecture du
 * vétérinaire ; un rapport validé entre-temps n'est jamais touché.
 */
final class WriteSurgeryReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 300;

    public function __construct(public int $interventionId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('surgery-report:'.$this->interventionId))->releaseAfter(60)->expireAfter($this->timeout + 100)];
    }

    public function handle(SurgeryReportWriter $writer, SurgeryReportSources $sources, SurgeryReportDrafts $drafts): void
    {
        $intervention = Intervention::withoutGlobalScopes()->find($this->interventionId);
        $report = SurgeryReport::query()->where('intervention_id', $this->interventionId)->first();
        if (! $intervention || $report?->status !== 'writing') {
            return;
        }

        $list = $sources->for($intervention);
        try {
            if ($this->attempts() > 1) {
                BudgetAnthropic::nouvelEssai('redaction');
            }
            $draft = $drafts->clean($writer->write($list, self::context($intervention)), $list);
        } catch (SurgeryReportImpossible $error) {
            report($error);
            $this->markFailed();

            return;
        }

        DB::transaction(function () use ($draft): void {
            $report = SurgeryReport::query()->where('intervention_id', $this->interventionId)->lockForUpdate()->first();
            if ($report?->status !== 'writing') {
                return;
            }
            // Les corrections du vétérinaire restent ; seules les rubriques non touchées prennent le nouveau texte
            $report->forceFill([
                'status' => 'draft',
                'error' => null,
                'sections' => SurgeryReportDrafts::merge($report->sections, $report->edited_sections, $draft['sections']),
                'draft' => $draft,
                'written_at' => now(),
            ])->save();
        });
        SurgeryReport::announce($intervention);

        // Une dictée arrivée pendant cette rédaction : un nouveau brouillon la prend (2e vérification)
        $isPending = SurgeryReport::query()->where('intervention_id', $this->interventionId)->where('is_redraft_pending', true)->update(['is_redraft_pending' => false]);
        if ($isPending === 1) {
            // Après la fin de celle-ci (son verrou WithoutOverlapping), jamais comptée comme un nouvel essai
            $drafts->request($intervention, delaySeconds: 5);
        }
    }

    public function failed(?Throwable $error): void
    {
        $this->markFailed();
    }

    private function markFailed(): void
    {
        $failed = SurgeryReport::query()->where('intervention_id', $this->interventionId)->where('status', 'writing')
            ->update(['status' => 'failed', 'error' => 'writer', 'is_redraft_pending' => false]);
        $intervention = Intervention::withoutGlobalScopes()->find($this->interventionId);
        if ($failed === 1 && $intervention) {
            SurgeryReport::announce($intervention);
        }
    }

    /**
     * Pour comprendre seulement, jamais recopié
     *
     * @return array<string, string>
     */
    private static function context(Intervention $intervention): array
    {
        $patient = $intervention->patient;

        return array_filter([
            'Espèce' => $patient?->espece?->libelle(),
            'Sexe' => $patient?->sexe?->value,
            'Âge' => $patient?->ne_le ? self::age($patient->ne_le) : null,
        ], fn ($v) => is_string($v) && $v !== '');
    }

    private static function age(CarbonInterface $birth): string
    {
        $months = (int) $birth->diffInMonths(now());

        return $months < 24 ? $months.' mois' : intdiv($months, 12).' ans';
    }
}
