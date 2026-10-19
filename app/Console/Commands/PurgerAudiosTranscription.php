<?php

namespace App\Console\Commands;

use App\Enums\StatutPartie;
use App\Models\PartieEnregistrement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * L'audio n'est gardé que le temps de le transcrire. Celui d'une partie
 * restée en erreur, ou jamais envoyée en entier, est effacé au bout de
 * quelques jours. Planifiée chaque nuit.
 */
class PurgerAudiosTranscription extends Command
{
    protected $signature = 'transcriptions:purger';

    protected $description = 'Efface l’audio des parties non transcrites devenues trop anciennes';

    public function handle(): int
    {
        $limite = now()->subDays((int) config('transcription.conservation_audio_jours'));
        $disque = Storage::disk('local');
        $effacees = 0;

        PartieEnregistrement::query()
            ->whereIn('statut', [StatutPartie::Envoi, StatutPartie::Recue, StatutPartie::Erreur])
            ->where('updated_at', '<', $limite)
            ->each(function (PartieEnregistrement $partie) use ($disque, &$effacees): void {
                if ($partie->fichier) {
                    $disque->delete($partie->fichier);
                }
                $disque->deleteDirectory($partie->dossierMorceaux());
                $partie->forceFill(['statut' => StatutPartie::Erreur, 'fichier' => null])->save();
                $effacees++;
            });

        $this->info($effacees.' partie(s) purgée(s).');

        return self::SUCCESS;
    }
}
