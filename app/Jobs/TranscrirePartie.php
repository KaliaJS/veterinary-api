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
use Illuminate\Support\Facades\DB;
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

    /** L'enregistrement relu en base est abandonné : l'audio de la partie est effacé. */
    private function abandonne(PartieEnregistrement $partie): bool
    {
        $statut = EnregistrementConsultation::withoutGlobalScopes()->whereKey($partie->enregistrement_consultation_id)->value('statut');
        if ($statut !== StatutEnregistrement::Abandonne->value && $statut !== StatutEnregistrement::Abandonne) {
            return false;
        }
        if ($partie->fichier) {
            Storage::disk('prive')->delete($partie->fichier);
        }
        $partie->forceFill(['fichier' => null])->save();

        return true;
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
            // Abandonné pendant ce contrôle : arrêt silencieux, pas d'erreur
            if (! $this->abandonne($partie)) {
                self::echec($partie, 'plafond');
            }

            return;
        }

        // Abandonné depuis le chargement : arrêt silencieux, avant même de lire
        // l'audio (effacé par l'abandon) ; relu encore juste avant l'envoi
        if ($this->abandonne($partie)) {
            return;
        }

        $partie->increment('tentatives');
        $fichier = $partie->fichier;

        // Copie locale le temps de l'envoi : le disque privé peut être un bucket
        $local = tempnam(sys_get_temp_dir(), 'audio');
        try {
            $entree = Storage::disk('prive')->readStream($fichier);
            $sortie = fopen((string) $local, 'wb');
            if (! is_resource($entree) || ! is_resource($sortie)) {
                throw new TranscriptionImpossible('Audio introuvable.');
            }
            stream_copy_to_stream($entree, $sortie);
            fclose($entree);
            fclose($sortie);

            // Abandonné entre-temps (relu juste avant l'envoi, pas celui chargé
            // plus haut) : jamais d'appel payant pour un texte qui ne sera pas écrit
            if ($this->abandonne($partie)) {
                return;
            }

            // Compté au budget de la plateforme avant l'envoi (24.10.2026) : un
            // essai sans réponse (délai, panne, worker arrêté) a pu être traité,
            // et facturé, par Deepgram. Retiré seulement s'il aboutit
            $essai = $consommation->essaiEnvoye((int) @filesize((string) $local));
            $resultat = $transcripteur->transcrire(
                (string) $local,
                $partie->format,
                $termes->pour($partie->enregistrement),
            );
        } catch (TranscriptionImpossible $erreur) {
            // Abandonné pendant la lecture (audio effacé) : arrêt silencieux
            if ($this->abandonne($partie)) {
                return;
            }
            report($erreur);
            self::echec($partie);

            return;
        } finally {
            if ($local !== false && is_file($local)) {
                unlink($local);
            }
        }

        // Abandonné pendant l'appel au fournisseur (24.10.2026) : la durée payée
        // est comptée, le texte n'est pas écrit. La fiche verrouillée, un
        // abandon au même instant attend ou est vu.
        $abandonne = DB::transaction(function () use ($partie, $resultat, $consommation, $essai): bool {
            $statut = EnregistrementConsultation::withoutGlobalScopes()
                ->whereKey($partie->enregistrement_consultation_id)
                ->lockForUpdate()
                ->value('statut');
            $abandonne = $statut === StatutEnregistrement::Abandonne;

            $partie->forceFill([
                'statut' => StatutPartie::Transcrite,
                'segments' => $abandonne ? null : $resultat->segments,
                'duree_secondes' => (int) ceil($resultat->duree),
                'requete_fournisseur' => $resultat->requete,
                'transcrite_le' => now(),
                'fichier' => null,
            ])->save();
            // L'estimation retirée avec l'enregistrement de la durée réelle,
            // pas avant : l'appel payé est toujours compté une fois (24.10.2026)
            $consommation->essaiAbouti($essai);

            return $abandonne;
        });

        // L'audio n'est pas gardé
        Storage::disk('prive')->delete($fichier);

        $consommation->surveiller();
        if ($partie->enregistrement->organisation) {
            $consommation->surveillerOrganisation($partie->enregistrement->organisation);
        }
        if (! $abandonne) {
            RedigerCompteRendu::siPret($partie->enregistrement);
        }
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
        $note = EnregistrementConsultation::withoutGlobalScopes()
            ->whereKey($partie->enregistrement_consultation_id)
            ->where('statut', StatutEnregistrement::Enregistrement->value)
            ->update(['erreur' => $raison]);
        $echoue = EnregistrementConsultation::withoutGlobalScopes()
            ->whereKey($partie->enregistrement_consultation_id)
            ->where('statut', StatutEnregistrement::Transcription->value)
            ->update(['statut' => StatutEnregistrement::Erreur->value, 'erreur' => $raison]);
        if ($note + $echoue > 0) {
            EnregistrementConsultation::annoncer($partie->enregistrement_consultation_id);
        }
    }
}
