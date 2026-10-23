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
        // En erreur, l'audio ne reste que le temps de relancer (23.10.2026)
        $limiteErreur = now()->subDays((int) config('transcription.conservation_erreur_jours'));
        $disque = Storage::disk('prive');
        $effacees = 0;

        PartieEnregistrement::query()
            ->where(fn ($requete) => $requete
                ->where(fn ($q) => $q->whereIn('statut', [StatutPartie::Envoi, StatutPartie::Recue])->where('updated_at', '<', $limite))
                ->orWhere(fn ($q) => $q->where('statut', StatutPartie::Erreur)->whereNotNull('fichier')->where('updated_at', '<', $limiteErreur)))
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
