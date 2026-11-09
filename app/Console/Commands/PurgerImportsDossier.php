<?php

namespace App\Console\Commands;

use App\Models\RecordImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Les découpes de gros PDF restées en route (09.11.2026) : au-delà d'un
 * jour, la découpe passe à la main et le fichier provisoire des pages
 * (texte médical, images) est effacé.
 */
class PurgerImportsDossier extends Command
{
    protected $signature = 'imports:purger';

    protected $description = 'Efface les pages provisoires des découpes de PDF restées en route depuis plus d’un jour';

    public function handle(): int
    {
        // Restées en route seulement (une découpe close n'a plus de pages provisoires)
        $old = RecordImport::withoutGlobalScopes()->where('created_at', '<', now()->subDay())->whereIn('status', ['splitting', 'ready', 'failed'])->where('created_at', '>', now()->subDays(30))->get();
        foreach ($old as $import) {
            Storage::disk('prive')->delete($import->pagesPath());
            if ($import->status === 'splitting') {
                $import->forceFill(['status' => 'failed', 'error' => 'splitter'])->save();
            }
        }
        $this->info($old->count().' découpe(s) vue(s).');

        return self::SUCCESS;
    }
}
