<?php

namespace App\Jobs;

use App\Enums\StatutEnregistrement;
use App\Enums\StatutPartie;
use App\Models\EnregistrementConsultation;
use App\Models\PartieEnregistrement;
use App\Services\Transcription\ConsommationTranscription;
use App\Services\Transcription\TermesTranscription;
use App\Services\Transcription\Transcripteur;
use App\Services\Transcription\TranscriptionImpossible;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Transcrit une partie reçue, puis efface son audio : seul le texte reste
 * (chiffré). La tâche ne porte que l'identifiant de la partie, jamais de
 * contenu. Quand c'est la dernière partie attendue, la rédaction du
 * brouillon part. Erreur temporaire : nouvel essai ; refus du fournisseur
 * ou essais épuisés : la partie passe en erreur, son audio reste pour une
 * relance (effacé au bout de quelques jours).
 */
final class TranscrirePartie implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 1000;

    public function __construct(public int $partieId) {}

    /**
     * Jamais deux fois en même temps pour la même partie : une
     * tâche reprise par un second worker pendant que le premier attend
     * le fournisseur ne relance pas un appel payant (elle repasse plus
     * tard et trouve le travail fait).
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('transcription-partie:'.$this->partieId))->releaseAfter(60)->expireAfter($this->timeout + 100)];
    }

    public function handle(Transcripteur $transcripteur, TermesTranscription $termes, ConsommationTranscription $consommation): void
    {
        $partie = PartieEnregistrement::query()->with('enregistrement.organisation', 'enregistrement.rendezVous')->find($this->partieId);
        if (! $partie || $partie->statut !== StatutPartie::Recue || ! $partie->fichier) {
            return;
        }

        // Le plafond et le budget revérifiés à chaque partie (23.10.2026) :
        // une longue consultation ne les dépasse pas en cours de route
        $organisation = $partie->enregistrement->organisation;
        if ($organisation && $consommation->refus($organisation)) {
            self::echec($partie, 'plafond');

            return;
        }

        $partie->increment('tentatives');
        $fichier = $partie->fichier;

        try {
            $resultat = $transcripteur->transcrire(
                Storage::disk('local')->path($fichier),
                $partie->format,
                $termes->pour($partie->enregistrement),
            );
        } catch (TranscriptionImpossible $erreur) {
            report($erreur);
            self::echec($partie);

            return;
        }

        $partie->forceFill([
            'statut' => StatutPartie::Transcrite,
            'segments' => $resultat->segments,
            'duree_secondes' => (int) ceil($resultat->duree),
            'requete_fournisseur' => $resultat->requete,
            'transcrite_le' => now(),
            'fichier' => null,
        ])->save();

        // L'audio n'est pas gardé
        Storage::disk('local')->delete($fichier);

        $consommation->surveiller();
        if ($partie->enregistrement->organisation) {
            $consommation->surveillerOrganisation($partie->enregistrement->organisation);
        }
        RedigerCompteRendu::siPret($partie->enregistrement);
    }

    public function failed(?Throwable $erreur): void
    {
        $partie = PartieEnregistrement::query()->find($this->partieId);
        if ($partie && $partie->statut === StatutPartie::Recue) {
            self::echec($partie);
        }
    }

    /** La partie passe en erreur ; si le vétérinaire a déjà terminé, l'enregistrement aussi. */
    private static function echec(PartieEnregistrement $partie, string $raison = 'transcription'): void
    {
        $partie->forceFill(['statut' => StatutPartie::Erreur])->save();

        // Encore en train d'enregistrer : la raison attend « terminer »
        EnregistrementConsultation::withoutGlobalScopes()
            ->whereKey($partie->enregistrement_consultation_id)
            ->where('statut', StatutEnregistrement::Enregistrement->value)
            ->update(['erreur' => $raison]);
        EnregistrementConsultation::withoutGlobalScopes()
            ->whereKey($partie->enregistrement_consultation_id)
            ->where('statut', StatutEnregistrement::Transcription->value)
            ->update(['statut' => StatutEnregistrement::Erreur->value, 'erreur' => $raison]);
    }
}
