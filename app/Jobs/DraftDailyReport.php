<?php

namespace App\Jobs;

use App\Models\Sejour;
use App\Models\StaySheet;
use App\Services\Hospitalisation\DailyReportImpossible;
use App\Services\Hospitalisation\DailyReports;
use App\Services\Hospitalisation\DailyReportWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Rédige le rapport de la journée d'un animal hospitalisé depuis sa feuille
 * (03.11.2026), comme RedigerCompteRendu : dans le worker, sans contexte
 * clinique (lectures bornées au séjour de la feuille), jamais deux fois en
 * même temps, un nouvel essai compté au budget. Le texte attend la
 * relecture ; en échec, la feuille dit qu'il n'a pas pu être rédigé.
 */
final class DraftDailyReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 240;

    public function __construct(public int $sheetId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('rapport-journee:'.$this->sheetId))->releaseAfter(60)->expireAfter($this->timeout + 100)];
    }

    public function handle(DailyReportWriter $writer, DailyReports $reports): void
    {
        $sheet = StaySheet::query()->find($this->sheetId);
        if (! $sheet || $sheet->report_status !== StaySheet::REPORT_DRAFTING) {
            return;
        }
        $sejour = Sejour::withoutGlobalScopes()->with(['patient' => fn ($q) => $q->withoutGlobalScopes(), 'clinique' => fn ($q) => $q->withoutGlobalScopes()])->find($sheet->sejour_id);
        if (! $sejour) {
            return;
        }

        try {
            // Une rédaction compte une fois au budget, même quand elle réessaie (03.11.2026)
            $text = $writer->write($reports->facts($sejour, $sheet->day->toDateString()));
        } catch (DailyReportImpossible $erreur) {
            report($erreur);
            $this->markFailed();

            return;
        }

        // Seulement s'il est toujours en rédaction
        DB::transaction(function () use ($text): void {
            $sheet = StaySheet::query()->whereKey($this->sheetId)->lockForUpdate()->first();
            if ($sheet?->report_status !== StaySheet::REPORT_DRAFTING) {
                return;
            }
            $sheet->forceFill([
                'report_status' => StaySheet::REPORT_TO_REVIEW,
                'report_text' => $text,
                'report_source' => 'ai',
                'report_error' => null,
                'report_drafted_at' => now(),
            ])->save();
        });
        Sejour::announce($sejour->id, $sejour->clinique_id);
    }

    public function failed(?Throwable $erreur): void
    {
        $this->markFailed();
    }

    /** En échec : un texte déjà là reste à relire, corrigeable et signable (03.11.2026) */
    private function markFailed(): void
    {
        $drafting = StaySheet::query()->whereKey($this->sheetId)->where('report_status', StaySheet::REPORT_DRAFTING);
        $failed = (clone $drafting)->whereNotNull('report_text')->where('report_text', '!=', '')
            ->update(['report_status' => StaySheet::REPORT_TO_REVIEW, 'report_error' => 'writing', 'updated_at' => now()])
            + $drafting->update(['report_status' => StaySheet::REPORT_FAILED, 'report_error' => 'writing', 'updated_at' => now()]);
        if ($failed === 1) {
            $sejour = Sejour::withoutGlobalScopes()->find(StaySheet::query()->whereKey($this->sheetId)->value('sejour_id'));
            if ($sejour) {
                Sejour::announce($sejour->id, $sejour->clinique_id);
            }
        }
    }
}
