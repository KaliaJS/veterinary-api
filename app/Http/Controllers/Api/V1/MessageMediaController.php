<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Jobs\TranscribeMessageMedia;
use App\Models\MessageMedia;
use App\Models\Organisation;
use App\Services\Messages\MessageMediaService;
use App\Services\Transcription\ConsommationTranscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Messages audio et vidéo, côté clinique (07.11.2026) : le fichier d'un
 * message, et sa transcription par « →A ». Le lisent : l'équipe de la
 * clinique du fil d'un client (une clinique sœur ne lit pas les fils de
 * l'autre), les deux collègues d'une conversation, les équipes des deux
 * cliniques d'un canal. Ailleurs, le message n'existe pas. Entre deux
 * cliniques, la transcription est facturée à celle qui la demande, et
 * l'autre la relit sans repayer.
 *
 * La transcription : une fois par message, relue ensuite sans repayer ;
 * seulement dans une clinique qui paie (abonnement réglé, identité
 * vérifiée), avec les plafonds et le budget de la transcription des
 * consultations ; facturée à l'organisation de la clinique.
 */
class MessageMediaController extends Controller
{
    public function show(int $media): StreamedResponse
    {
        return MessageMediaService::response($this->media($media));
    }

    /** La première image d'une vidéo (08.11.2026) */
    public function poster(int $media): StreamedResponse
    {
        return MessageMediaService::response($this->media($media), isPoster: true);
    }

    public function transcribe(int $media, ConsommationTranscription $consommation): JsonResponse
    {
        $contexte = app(ContexteClinique::class);
        $organisation = Organisation::query()->findOrFail($contexte->organisationId());
        $fichier = $this->media($media);

        // L'audio seulement (décision de Jim, 07.11.2026)
        if ($fichier->kind !== 'audio') {
            throw ValidationException::withMessages(['transcript' => 'Seuls les messages audio se transcrivent.']);
        }
        // Déjà transcrit, ou en cours : relu tel quel, rien ne repart chez Deepgram
        if (in_array($fichier->transcript_status, [MessageMedia::STATUS_DONE, MessageMedia::STATUS_PENDING], true)) {
            return $this->etat($fichier, $consommation, $organisation, 200);
        }
        // Effacé avec le compte, ou plus long ou plus lourd qu'un message audio : jamais chez Deepgram
        $impossible = MessageMediaService::untranscribable($fichier) ?? MessageMediaService::dailyRefusal($organisation->id);
        if ($impossible !== null) {
            throw ValidationException::withMessages(['transcript' => $impossible]);
        }
        $refus = $consommation->refus($organisation);
        if ($refus !== null) {
            throw ValidationException::withMessages(['transcript' => $refus]);
        }

        // Sous verrou : deux personnes qui touchent « →A » ensemble ne paient qu'une fois
        $lance = DB::transaction(function () use ($fichier, $organisation, $contexte): bool {
            $fiche = MessageMedia::query()->lockForUpdate()->findOrFail($fichier->id);
            if (in_array($fiche->transcript_status, [MessageMedia::STATUS_DONE, MessageMedia::STATUS_PENDING], true)) {
                return false;
            }
            if ($fiche->transcript_attempts >= (int) config('messages.media.transcription_attempts')) {
                throw ValidationException::withMessages(['transcript' => 'La transcription de ce message a échoué plusieurs fois : il reste à écouter.']);
            }
            $fiche->forceFill([
                'transcript_status' => MessageMedia::STATUS_PENDING,
                'transcript_organisation_id' => $organisation->id,
                'transcript_requested_by' => $contexte->employe()?->id,
            ])->save();

            return true;
        });
        if ($lance) {
            TranscribeMessageMedia::dispatch($fichier->id);
        }

        return $this->etat($fichier->refresh(), $consommation, $organisation, $lance ? 202 : 200);
    }

    private function etat(MessageMedia $fichier, ConsommationTranscription $consommation, Organisation $organisation, int $statut): JsonResponse
    {
        return response()->json(['data' => MessageMediaService::forClinic($fichier, $consommation->abonnementRefuse($organisation) === null)], $statut);
    }

    /**
     * Un fichier qu'on lit ici, sinon introuvable : d'un fil de client de la
     * clinique du contexte ; d'une conversation de l'employé avec un
     * collègue ; d'un canal entre la clinique du contexte et une autre.
     */
    private function media(int $id): MessageMedia
    {
        $contexte = app(ContexteClinique::class);
        $clinique = (int) $contexte->cliniqueId();
        $moi = (int) $contexte->employe()?->id;

        return MessageMedia::query()
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $client) => $client->whereNotNull('message_clientele_id')->where('clinique_id', $clinique))
                ->orWhereHas('staffMessage', fn (Builder $staff) => $staff->where(fn (Builder $deux) => $deux->where('sender_employee_id', $moi)->orWhere('recipient_employee_id', $moi)))
                ->orWhereHas('clinicMessage', fn (Builder $canal) => $canal->where(fn (Builder $deux) => $deux->where('sender_clinic_id', $clinique)->orWhere('recipient_clinic_id', $clinique))))
            ->findOrFail($id);
    }
}
