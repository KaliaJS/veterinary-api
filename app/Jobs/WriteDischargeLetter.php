<?php

namespace App\Jobs;

use App\Models\DischargeLetter;
use App\Models\Sejour;
use App\Services\BudgetAnthropic;
use App\Services\Hospitalisation\DischargeLetterImpossible;
use App\Services\Hospitalisation\DischargeLetters;
use App\Services\Hospitalisation\DischargeLetterSources;
use App\Services\Hospitalisation\DischargeLetterWriter;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Rédige le brouillon de la lettre de sortie (09.11.2026) depuis les
 * sources numérotées (séjour, traitements, rapports signés, rapport de
 * bloc, consignes, dictée). Le brouillon attend la relecture du
 * vétérinaire ; une lettre signée entre-temps n'est jamais touchée.
 */
final class WriteDischargeLetter implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 300;

    public function __construct(public int $sejourId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('discharge-letter:'.$this->sejourId))->releaseAfter(60)->expireAfter($this->timeout + 100)];
    }

    public function handle(DischargeLetterWriter $writer, DischargeLetterSources $sources, DischargeLetters $letters): void
    {
        $sejour = Sejour::withoutGlobalScopes()->find($this->sejourId);
        $letter = DischargeLetter::query()->where('sejour_id', $this->sejourId)->first();
        if (! $sejour || $letter?->status !== 'writing') {
            return;
        }

        $list = $sources->for($sejour);
        try {
            if ($this->attempts() > 1) {
                BudgetAnthropic::nouvelEssai('redaction');
            }
            $draft = $letters->clean($writer->write($list, self::context($sejour)), $list);
        } catch (DischargeLetterImpossible $error) {
            report($error);
            $this->markFailed();

            return;
        }

        DB::transaction(function () use ($draft): void {
            $letter = DischargeLetter::query()->where('sejour_id', $this->sejourId)->lockForUpdate()->first();
            if ($letter?->status !== 'writing') {
                return;
            }
            // Les corrections du vétérinaire restent ; seules les rubriques non touchées prennent le nouveau texte
            $letter->forceFill([
                'status' => 'draft',
                'error' => null,
                'sections' => DischargeLetters::merge($letter->sections, $letter->edited_sections, $draft['sections']),
                'draft' => $draft,
                'written_at' => now(),
            ])->save();
        });
        Sejour::announce($sejour->id, $sejour->clinique_id);

        // Une dictée arrivée pendant cette rédaction : un nouveau brouillon la prend
        $isPending = DischargeLetter::query()->where('sejour_id', $this->sejourId)->where('is_redraft_pending', true)->update(['is_redraft_pending' => false]);
        if ($isPending === 1) {
            $letters->request($sejour, delaySeconds: 5);
        }
    }

    public function failed(?Throwable $error): void
    {
        $this->markFailed();
    }

    private function markFailed(): void
    {
        $failed = DischargeLetter::query()->where('sejour_id', $this->sejourId)->where('status', 'writing')
            ->update(['status' => 'failed', 'error' => 'writer', 'is_redraft_pending' => false]);
        $sejour = Sejour::withoutGlobalScopes()->find($this->sejourId);
        if ($failed === 1 && $sejour) {
            Sejour::announce($sejour->id, $sejour->clinique_id);
        }
    }

    /**
     * Pour comprendre seulement, jamais recopié
     *
     * @return array<string, string>
     */
    private static function context(Sejour $sejour): array
    {
        $patient = $sejour->patient;

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
