<?php

namespace App\Jobs;

use App\Events\MessagesChangent;
use App\Models\ClinicMessage;
use App\Models\MessageMedia;
use App\Models\Organisation;
use App\Models\StaffMessage;
use App\Services\Messages\MessageMediaService;
use App\Services\Transcription\ConsommationTranscription;
use App\Services\Transcription\TermesTranscription;
use App\Services\Transcription\Transcripteur;
use App\Services\Transcription\TranscriptionImpossible;
use App\Support\TempsReel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Transcrit un message audio ou vidéo par Deepgram (bouton « →A »,
 * 07.11.2026), comme une partie de consultation : plafond et budget
 * revérifiés, essai compté au budget avant l'envoi, durée réelle facturée à
 * l'organisation qui l'a demandé. Le fichier reste : c'est le message. La
 * tâche ne porte que l'identifiant ; le texte ne va jamais au journal. Fini
 * ou en échec, la clinique l'apprend en temps réel, sans le contenu.
 */
final class TranscribeMessageMedia implements ShouldQueue
{
    use Queueable;

    /** Les attentes derrière une autre transcription de l'organisation ne comptent pas comme des échecs */
    public int $tries = 30;

    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $timeout = 300;

    public function __construct(public int $mediaId) {}

    /**
     * Jamais deux appels en même temps pour le même message : une tâche
     * reprise par un second worker ne repaie pas. Une seule transcription de
     * messages à la fois par organisation (cinquième vérification) : la
     * durée réelle de l'une est comptée avant que la suivante ne réserve.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        $organisationId = MessageMedia::query()->whereKey($this->mediaId)->value('transcript_organisation_id');

        return [
            (new WithoutOverlapping('transcription-message:'.$this->mediaId))->releaseAfter(30)->expireAfter($this->timeout + 60),
            (new WithoutOverlapping('transcription-messages-organisation:'.($organisationId ?? 0)))->releaseAfter(10)->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(Transcripteur $transcripteur, TermesTranscription $termes, ConsommationTranscription $consommation): void
    {
        $media = MessageMedia::query()->find($this->mediaId);
        if (! $media || $media->transcript_status !== MessageMedia::STATUS_PENDING) {
            return;
        }
        $organisation = Organisation::query()->find($media->transcript_organisation_id);
        // Une vidéo, un fichier effacé, plus long ou plus lourd qu'un message audio : jamais chez Deepgram
        if ($organisation === null || $consommation->refus($organisation) !== null || MessageMediaService::untranscribable($media) !== null
            || MessageMediaService::dailyRefusal($organisation->id) !== null) {
            self::fail($media);

            return;
        }

        $essai = null;
        $reservation = null;
        $maxSeconds = (int) config('messages.media.audio.max_seconds');
        $local = tempnam(sys_get_temp_dir(), 'message');
        try {
            // Copie locale le temps de l'envoi : le disque privé peut être un bucket
            $entree = Storage::disk('prive')->readStream($media->path);
            $sortie = fopen((string) $local, 'wb');
            if (! is_resource($entree) || ! is_resource($sortie)) {
                throw new TranscriptionImpossible('Fichier du message introuvable.');
            }
            stream_copy_to_stream($entree, $sortie);
            fclose($entree);
            fclose($sortie);

            // L'estimation : la durée lue, ou ce que dit la taille au débit
            // courant le plus bas d'Opus (6 kbit/s). Ce n'est pas un pire : un
            // son en silence (DTX, 0,4 kbit/s) peut durer bien plus.
            // Ce qui est garanti (cinquième vérification) : l'estimation se
            // réserve, sous verrou, dans ce qui reste du jour à l'organisation,
            // et ses transcriptions passent une à la fois ; la durée réelle
            // remplace la réservation dès que Deepgram répond. Une organisation
            // ne fait donc payer à la plateforme, pour ses messages, que son
            // plafond du jour (3 h) plus, au pire, la durée cachée d'un seul
            // fichier. Le budget du mois compte la même réservation, une fois
            $annoncee = (int) ceil($media->duration_ms / 1000);
            $parLaTaille = (int) ceil(max(0, (int) @filesize((string) $local)) * 8 / max(1, (int) config('messages.media.min_audio_bitrate')));
            $reservation = MessageMediaService::reserveToday($organisation->id, max($annoncee, $parLaTaille));
            if ($reservation === null) {
                // Plus rien aujourd'hui : refus clair au prochain « →A », sans facturation, jusqu'au lendemain
                self::fail($media);

                return;
            }
            $media->increment('transcript_attempts');
            $essai = $consommation->essaiDeSecondes($reservation['secondes']);
            $resultat = $transcripteur->transcrire((string) $local, $media->mime, $termes->pourOrganisation($organisation));
        } catch (TranscriptionImpossible $erreur) {
            // Refus net de Deepgram (4xx, fichier illisible) : rien n'est facturé,
            // l'estimation sort du budget du mois (troisième vérification)
            if ($essai !== null) {
                $consommation->essaiAbouti($essai);
            }
            if ($reservation !== null) {
                MessageMediaService::settleToday($reservation, 0);
            }
            report($erreur);
            self::fail($media);

            return;
        } finally {
            if ($local !== false && is_file($local)) {
                unlink($local);
            }
        }

        // Deuxième vérification (07.11.2026) : Deepgram ne sait pas couper un
        // fichier à une durée donnée, et sans ffmpeg on ne coupe pas avant.
        // Plus long que permis (au-delà de 2 s de marge), il est refusé après
        // coup : la durée payée compte au budget de la plateforme, le texte
        // n'est pas gardé, la clinique ne paie rien, et le message ne repart plus
        $traitees = (int) ceil($resultat->duree);
        $tropLong = $resultat->duree > $maxSeconds + 2;
        DB::transaction(function () use ($media, $resultat, $consommation, $essai, $maxSeconds, $traitees, $tropLong, $reservation): void {
            $media->forceFill($tropLong ? [
                'transcript_status' => MessageMedia::STATUS_FAILED,
                'transcript' => null,
                'transcript_seconds' => null,
                'transcript_attempts' => (int) config('messages.media.transcription_attempts'),
                'provider_seconds' => $traitees,
                'provider_at' => now(),
                'transcript_request_id' => $resultat->requete,
            ] : [
                'transcript_status' => MessageMedia::STATUS_DONE,
                'transcript' => trim(implode(' ', array_column($resultat->segments, 'texte'))),
                // Facturée dans la limite du genre ; la plateforme compte la durée payée
                'transcript_seconds' => min($traitees, $maxSeconds),
                'provider_seconds' => $traitees,
                'provider_at' => now(),
                'transcript_request_id' => $resultat->requete,
                'transcribed_at' => now(),
            ])->save();
            // L'estimation retirée avec la durée réelle, pas avant ; la réservation du jour devient la durée payée
            $consommation->essaiAbouti($essai);
            MessageMediaService::settleToday($reservation, $traitees);
        });
        if ($tropLong) {
            report(new TranscriptionImpossible('Message '.$media->id.' plus long que permis chez Deepgram : '.$traitees.' s.'));
        }

        $consommation->surveiller();
        $consommation->surveillerOrganisation($organisation);
        TempsReel::alertesDeLOrganisation($organisation->id);
        self::announce($media);
    }

    public function failed(?Throwable $erreur): void
    {
        $media = MessageMedia::query()->find($this->mediaId);
        if ($media && $media->transcript_status === MessageMedia::STATUS_PENDING) {
            self::fail($media);
        }
    }

    private static function fail(MessageMedia $media): void
    {
        $media->forceFill(['transcript_status' => MessageMedia::STATUS_FAILED])->save();
        self::announce($media);
    }

    /**
     * Ceux qui lisent le fil le relisent (sans contenu dans l'annonce) : la
     * clinique du fil d'un client, les deux collègues, les deux cliniques.
     */
    private static function announce(MessageMedia $media): void
    {
        if ($media->staff_message_id !== null) {
            $message = StaffMessage::withoutGlobalScopes()->with(['sender', 'recipient'])->find($media->staff_message_id);
            foreach (array_filter([$message?->sender?->user_id, $message?->recipient?->user_id]) as $userId) {
                TempsReel::diffuser(fn () => MessagesChangent::dispatch('utilisateur.'.$userId));
            }

            return;
        }
        if ($media->clinic_message_id !== null) {
            $message = ClinicMessage::query()->find($media->clinic_message_id);
            foreach (array_filter([$message?->sender_clinic_id, $message?->recipient_clinic_id]) as $cliniqueId) {
                TempsReel::diffuser(fn () => MessagesChangent::dispatch('clinique.'.$cliniqueId));
            }

            return;
        }
        $userId = $media->message()->value('user_id');
        TempsReel::diffuser(fn () => MessagesChangent::dispatch('clinique.'.$media->clinique_id, $userId === null ? null : (int) $userId));
    }
}
