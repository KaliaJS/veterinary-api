<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Events\MessagesChangent;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Espace\EnvoyerMessageRequest;
use App\Http\Requests\V1\Espace\SendOwnerMessageAttachmentsRequest;
use App\Http\Requests\V1\Espace\SendOwnerMessageMediaRequest;
use App\Models\Clinique;
use App\Models\MessageAttachment;
use App\Models\MessageClientele;
use App\Models\MessageMedia;
use App\Models\User;
use App\Services\Messages\MessageAttachmentService;
use App\Services\Messages\MessageMediaService;
use App\Services\Messages\MessageReceipts;
use App\Support\TempsReel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Messages (proto : un fil avec « la clinique », réponse sous 24 h).
 * Un compte peut être client de plusieurs cliniques : un fil par
 * clinique, parmi les cliniques des organisations où le compte a une
 * fiche. Ouvrir un fil marque lus les messages de la clinique.
 */
class MessageProprietaireController extends Controller
{
    /** Fils disponibles + fil demandé (ou le premier), avec ses messages. */
    public function index(Request $request, ContexteProprietaire $contexte, MessageReceipts $receipts): JsonResponse
    {
        // L'app du propriétaire relit ses messages : ceux des cliniques lui sont distribués (08.11.2026)
        $receipts->ownerReceived($contexte->user()->id);
        $cliniques = $this->cliniques($contexte);
        $cliniqueId = $request->integer('clinique_id') ?: $cliniques->first()?->id;
        // Une clinique inconnue (lien d'un ancien e-mail) : la première, pas un écran vide
        $clinique = $cliniques->firstWhere('id', $cliniqueId) ?? $cliniques->first();

        $messages = collect();
        if ($clinique) {
            // Lus : la clinique voit ses coches passer au bleu
            $receipts->read(
                MessageClientele::query()->where('user_id', $contexte->user()->id)->where('clinique_id', $clinique->id)->where('auteur', 'clinique'),
                'lu_le', 'clinique.'.$clinique->id, $contexte->user()->id,
            );

            $messages = MessageClientele::query()
                ->with('employe', 'media', 'attachments')
                ->where('user_id', $contexte->user()->id)
                ->where('clinique_id', $clinique->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();
        }

        // Après le marquage : le fil ouvert ne compte plus de non-lus.
        $nonLus = MessageClientele::query()
            ->where('user_id', $contexte->user()->id)
            ->where('auteur', 'clinique')
            ->whereNull('lu_le')
            ->get()
            ->groupBy('clinique_id')
            ->map->count();

        return response()->json(['data' => [
            'cliniques' => $cliniques->map(fn (Clinique $c): array => [
                'id' => $c->id,
                'nom' => $c->nom,
                'tel' => $c->tel,
                'non_lus' => (int) ($nonLus[$c->id] ?? 0),
                // Réglages de la clinique (28.10.2026) : la raison est dite quand il ne peut pas
                'peut_ecrire' => $this->refus($c, $contexte) === null,
                'raison' => $this->refus($c, $contexte),
            ])->values()->all(),
            'clinique_id' => $clinique?->id,
            'messages' => $messages->map(fn (MessageClientele $m): array => $this->message($m))->all(),
        ]]);
    }

    public function envoyer(EnvoyerMessageRequest $request, ContexteProprietaire $contexte): JsonResponse
    {
        $colonnes = $this->nouveauMessage((int) $request->validated('clinique_id'), $contexte);
        $message = MessageClientele::create([...$colonnes, 'texte' => trim($request->validated('texte'))]);
        $this->annoncer($message);

        return response()->json(['data' => $this->message($message)], 201);
    }

    /** Un message audio ou une vidéo ronde à la clinique (07.11.2026), aux mêmes conditions qu'un texte */
    public function envoyerMedia(SendOwnerMessageMediaRequest $request, ContexteProprietaire $contexte, MessageMediaService $media): JsonResponse
    {
        $colonnes = $this->nouveauMessage((int) $request->validated('clinique_id'), $contexte);
        // Les quotas sous verrou du compte, du contrôle à l'écriture : deux envois au même instant ne les dépassent pas
        $message = DB::transaction(function () use ($request, $contexte, $media, $colonnes): MessageClientele {
            User::query()->whereKey($contexte->user()->id)->lockForUpdate()->first();
            $this->exigerQuota($contexte, (string) $request->validated('kind'), (int) $request->file('file')?->getSize());

            return $media->store($colonnes, $request->file('file'), (string) $request->validated('kind'), $request->durationMs(), $request->validated('peaks'), $request->file('poster'));
        });
        $this->annoncer($message);

        return response()->json(['data' => $this->message($message)], 201);
    }

    /**
     * Des photos et des PDF à la clinique (08.11.2026), aux mêmes conditions
     * qu'un texte, dans ses plafonds : le poids du jour (avec l'audio et la
     * vidéo), et un nombre de fichiers par heure.
     */
    public function sendAttachments(SendOwnerMessageAttachmentsRequest $request, ContexteProprietaire $contexte, MessageAttachmentService $pieces): JsonResponse
    {
        $colonnes = $this->nouveauMessage((int) $request->validated('clinique_id'), $contexte);
        $fichiers = $request->uploads();
        $message = DB::transaction(function () use ($request, $contexte, $pieces, $colonnes, $fichiers): MessageClientele {
            User::query()->whereKey($contexte->user()->id)->lockForUpdate()->first();
            $this->requireAttachmentQuota($contexte, count($fichiers), (int) array_sum(array_map(fn ($f): int => (int) $f->getSize(), $fichiers)));

            return $pieces->storeWith(
                fn (string $texte): MessageClientele => MessageClientele::query()->create([...$colonnes, 'texte' => $texte]),
                'message_clientele_id', (int) $colonnes['clinique_id'], $request->readers(), $request->validated('texte'),
            );
        });
        $this->annoncer($message);

        return response()->json(['data' => $this->message($message)], 201);
    }

    /** Une pièce jointe de l'un de ses fils (ou l'aperçu d'une photo), sinon introuvable */
    public function attachment(int $attachment, ContexteProprietaire $contexte): StreamedResponse
    {
        return MessageAttachmentService::response($this->ownedAttachment($attachment, $contexte));
    }

    public function attachmentThumb(int $attachment, ContexteProprietaire $contexte): StreamedResponse
    {
        return MessageAttachmentService::response($this->ownedAttachment($attachment, $contexte), isThumb: true);
    }

    private function ownedAttachment(int $id, ContexteProprietaire $contexte): MessageAttachment
    {
        return MessageAttachment::query()->whereHas('message', fn ($q) => $q->where('user_id', $contexte->user()->id))->findOrFail($id);
    }

    /**
     * Pièces jointes du propriétaire : 60 fichiers par heure au plus ; leur
     * poids compte dans les 200 Mo du jour, avec l'audio et la vidéo.
     */
    private function requireAttachmentQuota(ContexteProprietaire $contexte, int $nombre, int $octets): void
    {
        $siennes = fn () => MessageAttachment::query()->whereHas('message', fn ($q) => $q->where('user_id', $contexte->user()->id)->where('auteur', 'proprietaire'));
        if ($siennes()->where('created_at', '>', now()->subHour())->count() + $nombre > (int) config('messages.attachments.owner_files_per_hour')) {
            throw ValidationException::withMessages(['files' => sprintf('%d fichiers par heure au plus : réessayez un peu plus tard.', (int) config('messages.attachments.owner_files_per_hour'))]);
        }
        $medias = MessageMedia::query()->whereHas('message', fn ($q) => $q->where('user_id', $contexte->user()->id)->where('auteur', 'proprietaire'))->where('created_at', '>', now()->subDay())->sum('size_bytes');
        if ((int) $medias + (int) $siennes()->where('created_at', '>', now()->subDay())->sum('size_bytes') + $octets > (int) config('messages.media.owner_bytes_per_day')) {
            throw ValidationException::withMessages(['files' => '200 Mo d’envois par jour au plus : réessayez demain.']);
        }
    }

    /** La première image d'une vidéo de l'un de ses fils (08.11.2026) */
    public function mediaPoster(int $media, ContexteProprietaire $contexte): StreamedResponse
    {
        $fichier = MessageMedia::query()->whereHas('message', fn ($q) => $q->where('user_id', $contexte->user()->id))->findOrFail($media);

        return MessageMediaService::response($fichier, isPoster: true);
    }

    /** Le fichier d'un message audio ou vidéo de l'un de ses fils, sinon introuvable */
    public function media(int $media, ContexteProprietaire $contexte): StreamedResponse
    {
        $fichier = MessageMedia::query()
            ->whereHas('message', fn ($q) => $q->where('user_id', $contexte->user()->id))
            ->findOrFail($media);

        return MessageMediaService::response($fichier);
    }

    /**
     * Un propriétaire envoie au plus 10 vidéos par heure et 200 Mo
     * d'enregistrements par jour (vérification du 07.11.2026).
     */
    private function exigerQuota(ContexteProprietaire $contexte, string $kind, int $octets): void
    {
        $siens = fn () => MessageMedia::query()->whereHas('message', fn ($q) => $q->where('user_id', $contexte->user()->id)->where('auteur', 'proprietaire'));
        if ($kind === 'video' && $siens()->where('kind', 'video')->where('created_at', '>', now()->subHour())->count() >= (int) config('messages.media.owner_videos_per_hour')) {
            throw ValidationException::withMessages(['file' => 'Dix vidéos par heure au plus : réessayez un peu plus tard.']);
        }
        // Le poids du jour compte aussi les pièces jointes (vérification du 08.11.2026 : dans les deux sens)
        $pieces = MessageAttachment::query()->whereHas('message', fn ($q) => $q->where('user_id', $contexte->user()->id)->where('auteur', 'proprietaire'))->where('created_at', '>', now()->subDay())->sum('size_bytes');
        if ((int) $siens()->where('created_at', '>', now()->subDay())->sum('size_bytes') + (int) $pieces + $octets > (int) config('messages.media.owner_bytes_per_day')) {
            throw ValidationException::withMessages(['file' => '200 Mo d’envois par jour au plus : réessayez demain.']);
        }
    }

    /**
     * Les colonnes d'un message du propriétaire à cette clinique, s'il en
     * est client et qu'elle reçoit ses messages.
     *
     * @return array<string, mixed>
     */
    private function nouveauMessage(int $cliniqueId, ContexteProprietaire $contexte): array
    {
        if (! $this->cliniques($contexte)->contains('id', $cliniqueId)) {
            throw ValidationException::withMessages(['clinique_id' => 'Vous n’êtes pas client de cette clinique.']);
        }
        $refus = $this->refus($this->cliniques($contexte)->firstWhere('id', $cliniqueId), $contexte);
        if ($refus !== null) {
            throw ValidationException::withMessages(['clinique_id' => $refus]);
        }

        // Sa fiche client dans l'organisation de la clinique : le fil reste attribuable (28.10.2026)
        $organisation = $this->cliniques($contexte)->firstWhere('id', $cliniqueId)?->societe()->withoutGlobalScopes()->value('organisation_id');

        return [
            'clinique_id' => $cliniqueId,
            'user_id' => $contexte->user()->id,
            'client_id' => $contexte->clients()->firstWhere('organisation_id', $organisation)?->id,
            'auteur' => 'proprietaire',
        ];
    }

    /** La clinique l'apprend aussitôt (sans le contenu : il se lit par l'API) */
    private function annoncer(MessageClientele $message): void
    {
        TempsReel::diffuser(fn () => MessagesChangent::dispatch('clinique.'.$message->clinique_id, (int) $message->user_id));
    }

    /** « Réponses uniquement » : la réponse reste ouverte ce nombre de jours après le dernier message de la clinique */
    private const int JOURS_POUR_REPONDRE = 14;

    /** Pourquoi le propriétaire ne peut pas écrire à cette clinique (ses réglages), ou null. */
    private function refus(Clinique $clinique, ContexteProprietaire $contexte): ?string
    {
        $tel = filled($clinique->tel) ? ' : appelez-la au '.$clinique->tel : '';
        if ($clinique->messages_recus === false) {
            return 'La clinique ne reçoit pas de messages'.$tel.'.';
        }
        // « Réponses uniquement » : répondre dans les 14 jours après le dernier
        // message de la clinique (décision de Jim, 28.10.2026, choix B)
        if ($clinique->messages_reponses_seulement) {
            $dernier = MessageClientele::query()->where('clinique_id', $clinique->id)->where('user_id', $contexte->user()->id)->where('auteur', 'clinique')->max('created_at');
            if ($dernier === null || CarbonImmutable::parse($dernier)->lessThan(now()->subDays(self::JOURS_POUR_REPONDRE))) {
                $appel = filled($clinique->tel) ? ' Pour lui écrire, appelez-la au '.$clinique->tel.'.' : '';

                return ($dernier === null
                    ? 'La clinique répond aux messages qu’elle vous envoie.'
                    : 'Le délai de '.self::JOURS_POUR_REPONDRE.' jours pour répondre à son dernier message est passé.').$appel;
            }
        }

        return null;
    }

    /**
     * Cliniques des organisations où le compte a une fiche.
     *
     * @return Collection<int, Clinique>
     */
    private function cliniques(ContexteProprietaire $contexte)
    {
        $organisations = $contexte->clients()->pluck('organisation_id')->unique()->all();

        if ($organisations === []) {
            return collect();
        }

        return Clinique::withoutGlobalScopes()
            ->whereHas('societe', fn ($q) => $q->whereIn('organisation_id', $organisations))
            ->orderBy('id')
            ->get();
    }

    /** @return array<string, mixed> */
    private function message(MessageClientele $m): array
    {
        return [
            'id' => $m->id,
            'auteur' => $m->auteur,
            'de_moi' => $m->auteur === 'proprietaire',
            'signature' => $m->auteur === 'clinique' ? ($m->employe?->nom) : null,
            'texte' => $m->texte,
            'envoye_le' => $m->created_at?->toIso8601String(),
            'lu_le' => $m->lu_le?->toIso8601String(),
            'status' => $m->auteur === 'proprietaire' ? MessageReceipts::status($m->delivered_at, $m->lu_le) : null,
            // Les pièces jointes et la légende (08.11.2026)
            'attachments' => $m->relationLoaded('attachments') ? MessageAttachmentService::payload($m->attachments) : [],
            'caption' => $m->relationLoaded('attachments') ? MessageAttachmentService::caption($m->texte, $m->attachments) : null,
            // Audio ou vidéo : le fichier, sa durée et son onde ; la transcription reste à la clinique
            'media' => $m->relationLoaded('media') && $m->media ? MessageMediaService::forOwner($m->media) : null,
        ];
    }
}
