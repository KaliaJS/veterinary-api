<?php

namespace App\Jobs;

use App\Models\ReferralLetter;
use App\Services\BudgetAnthropic;
use App\Services\Referrals\ReferralLetterImpossible;
use App\Services\Referrals\ReferralLetters;
use App\Services\Referrals\ReferralLetterSources;
use App\Services\Referrals\ReferralLetterWriter;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Rédige le brouillon de la lettre de référence (09.11.2026) depuis les
 * sources numérotées (pièces jointes, notes, dictée). Le brouillon attend la
 * relecture du vétérinaire ; une lettre signée entre-temps n'est jamais
 * touchée.
 */
final class WriteReferralLetter implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 300;

    public function __construct(public int $letterId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('referral-letter:'.$this->letterId))->releaseAfter(60)->expireAfter($this->timeout + 100)];
    }

    public function handle(ReferralLetterWriter $writer, ReferralLetterSources $sources, ReferralLetters $letters): void
    {
        $letter = ReferralLetter::withoutGlobalScopes()->find($this->letterId);
        if ($letter?->status !== 'writing') {
            return;
        }

        // Le moment où les sources sont lues : un changement ensuite rend ce brouillon périmé
        $readAt = now()->toIso8601String();
        $list = $sources->for($letter);
        try {
            if ($this->attempts() > 1) {
                BudgetAnthropic::nouvelEssai('redaction');
            }
            $draft = $letters->clean($writer->write($list, self::context($letter)), $list);
        } catch (ReferralLetterImpossible $error) {
            report($error);
            $this->markFailed();

            return;
        }

        DB::transaction(function () use ($draft, $readAt): void {
            $locked = ReferralLetter::withoutGlobalScopes()->whereKey($this->letterId)->lockForUpdate()->first();
            if ($locked?->status !== 'writing') {
                return;
            }
            // Les corrections du vétérinaire restent ; seules les rubriques non touchées prennent le nouveau texte
            $locked->forceFill([
                'status' => 'draft',
                'error' => null,
                'sections' => ReferralLetters::merge($locked->sections, $locked->edited_sections, $draft['sections']),
                'draft' => [...$draft, 'read_at' => $readAt],
                'written_at' => now(),
            ])->save();
        });
        ReferralLetter::announce($letter->id, $letter->clinique_id);

        // Une dictée ou des notes arrivées pendant cette rédaction : un nouveau brouillon les prend
        $isPending = ReferralLetter::withoutGlobalScopes()->whereKey($this->letterId)->where('is_redraft_pending', true)->update(['is_redraft_pending' => false]);
        if ($isPending === 1) {
            $letters->request($letter, delaySeconds: 5);
        }
    }

    public function failed(?Throwable $error): void
    {
        $this->markFailed();
    }

    private function markFailed(): void
    {
        $failed = ReferralLetter::withoutGlobalScopes()->whereKey($this->letterId)->where('status', 'writing')
            ->update(['status' => 'failed', 'error' => 'writer', 'is_redraft_pending' => false]);
        $letter = ReferralLetter::withoutGlobalScopes()->find($this->letterId);
        if ($failed === 1 && $letter) {
            ReferralLetter::announce($letter->id, $letter->clinique_id);
        }
    }

    /**
     * Pour comprendre seulement, jamais recopié
     *
     * @return array<string, string>
     */
    private static function context(ReferralLetter $letter): array
    {
        $patient = $letter->patient;

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
