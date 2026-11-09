<?php

namespace App\Jobs;

use App\Models\RecordImport;
use App\Services\CompteurMensuel;
use App\Services\Imports\RecordImports;
use App\Services\Imports\RecordSplitImpossible;
use App\Services\Imports\RecordSplitter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Propose la découpe d'un gros PDF reçu (09.11.2026) par Claude Haiku 4.5.
 * Le fichier provisoire des pages est effacé dès que la découpe est
 * proposée, ou qu'elle a échoué pour de bon.
 */
final class SplitRecordImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $timeout = 300;

    public function __construct(public int $importId) {}

    public function handle(RecordSplitter $splitter): void
    {
        $import = RecordImport::withoutGlobalScopes()->find($this->importId);
        if ($import?->status !== 'splitting') {
            return;
        }
        $pages = json_decode((string) Storage::disk('prive')->get($import->pagesPath()), true);
        if (! is_array($pages)) {
            $this->fail(new RecordSplitImpossible('Pages illisibles.'));

            return;
        }
        try {
            // Un nouvel essai compte comme le premier : par tranche de 50 pages
            if ($this->attempts() > 1) {
                CompteurMensuel::ajouter('anthropic:essais:decoupe', (int) ceil($import->pages_count / 50));
            }
            $documents = $splitter->split($pages);
        } catch (RecordSplitImpossible $error) {
            report($error);
            $this->markFailed();

            return;
        }

        // Sous verrou : une découpe close à la main entre-temps n'est jamais rouverte
        $isWritten = DB::transaction(function () use ($documents): bool {
            $locked = RecordImport::withoutGlobalScopes()->whereKey($this->importId)->lockForUpdate()->first();
            if ($locked?->status !== 'splitting') {
                return false;
            }
            $locked->forceFill(['status' => 'ready', 'error' => null, 'proposal' => RecordImports::normalize($documents, $locked->pages_count)])->save();

            return true;
        });
        Storage::disk('prive')->delete($import->pagesPath());
        if (! $isWritten) {
            return;
        }
        RecordImport::announce($import->id, $import->clinique_id);
    }

    public function failed(?Throwable $error): void
    {
        $this->markFailed();
    }

    private function markFailed(): void
    {
        $import = RecordImport::withoutGlobalScopes()->find($this->importId);
        if ($import === null) {
            return;
        }
        Storage::disk('prive')->delete($import->pagesPath());
        if ($import->status === 'splitting') {
            $import->forceFill(['status' => 'failed', 'error' => 'splitter'])->save();
            RecordImport::announce($import->id, $import->clinique_id);
        }
    }
}
