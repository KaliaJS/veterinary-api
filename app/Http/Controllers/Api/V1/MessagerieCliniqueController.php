<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\PushType;
use App\Events\MessagesChangent;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Messages\SendMessageAttachmentsRequest;
use App\Http\Requests\V1\Messages\SendMessageMediaRequest;
use App\Http\Resources\V1\Espace\AnimalProprietaireResource;
use App\Mail\ReponseClinique;
use App\Models\Client;
use App\Models\Document;
use App\Models\MessageClientele;
use App\Models\Organisation;
use App\Models\Patient;
use App\Models\PreferencesProprietaire;
use App\Services\Messages\InternalMessaging;
use App\Services\Messages\MessageAttachmentService;
use App\Services\Messages\MessageMediaService;
use App\Services\Messages\MessageMutes;
use App\Services\Messages\MessageReceipts;
use App\Services\Notifications\Push;
use App\Support\TempsReel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Messagerie de la clinique (décision de Jim, 28.10.2026) : les fils de
 * ses clients, les non-lus, la réponse signée de l'employé ; la clinique
 * peut aussi ouvrir un fil. Un fil se désigne par sa clé : « u12 » (le
 * compte du client) ou « c34 » (sa fiche, quand le compte a été effacé).
 * Un compte effacé ou détaché de sa fiche garde son fil lisible ; on n'y
 * répond plus. Le propriétaire est prévenu en temps réel, et par e-mail
 * s'il l'a choisi.
 *
 * Dans la même liste (décision de Jim, 01.11.2026) : les collègues (« e12 »)
 * et les autres cliniques (« k34 »), par InternalMessaging. Chaque fil dit
 * son genre (`kind` : client, staff, clinic), son nom et son sous-titre.
 */
class MessagerieCliniqueController extends Controller
{
    public function __construct(private InternalMessaging $internes, private MessageReceipts $receipts, private MessageMutes $mutes) {}

    /** Les fils de la clinique, de ses clients, de l'employé avec ses collègues, avec les autres cliniques : le plus récent d'abord. */
    public function index(): JsonResponse
    {
        // L'app de la clinique relit sa liste : les messages des clients lui sont distribués (08.11.2026)
        $this->receipts->clinicReceivedFromOwners((int) app(ContexteClinique::class)->cliniqueId());
        // Le mode silencieux de chacun (08.11.2026) : la conversation ne compte plus dans la pastille du menu
        $silence = $this->mutes->threadsOf((int) request()->user()?->id, app(ContexteClinique::class)->cliniqueId());
        $fils = collect([...$this->filsDesClients(), ...$this->internes->threads()])
            ->map(fn (array $fil): array => [...$fil, 'muted' => isset($silence[$fil['cle']])])
            ->sortByDesc(fn (array $fil): string => (string) ($fil['dernier']['envoye_le'] ?? ''))->values();

        return response()->json(['data' => $fils]);
    }

    /** « À : » : les collègues et les cliniques (les clients se cherchent par /clients) */
    public function contacts(Request $request): JsonResponse
    {
        $q = (string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? '');

        return response()->json(['data' => $this->internes->contacts($q)]);
    }

    /** @return list<array<string, mixed>> */
    private function filsDesClients(): array
    {
        $cliniqueId = app(ContexteClinique::class)->cliniqueId();
        $messages = MessageClientele::query()->where('clinique_id', $cliniqueId)->orderByDesc('created_at')->orderByDesc('id')->get()
            ->groupBy(fn (MessageClientele $m): string => $m->user_id !== null ? 'u'.$m->user_id : 'c'.$m->client_id);
        // Les fiches de l'organisation : par compte (lié) et par fiche (gardée sur les messages)
        $parCompte = Client::query()->whereIn('user_id', $messages->flatten()->pluck('user_id')->filter()->unique())->get()->keyBy('user_id');
        $parFiche = Client::query()->whereIn('id', $messages->flatten()->pluck('client_id')->filter()->unique())->get()->keyBy('id');
        $animaux = Client::query()->whereIn('id', $parCompte->pluck('id')->merge($parFiche->keys())->unique())
            ->with('patientsParNom')->get()->mapWithKeys(fn (Client $c): array => [$c->id => $c->patientsParNom->pluck('nom')->take(2)->implode(', ')]);

        return $messages->map(function ($fil, string $cle) use ($parCompte, $parFiche, $animaux): array {
            /** @var MessageClientele $dernier */
            $dernier = $fil->first();
            $lie = $dernier->user_id !== null ? ($parCompte[$dernier->user_id] ?? null) : null;
            $fiche = $lie ?? ($parFiche[$fil->pluck('client_id')->filter()->first()] ?? null);

            $nom = $fiche ? trim($fiche->prenom.' '.$fiche->nom) : null;

            return [
                'cle' => $cle,
                'kind' => 'client',
                'name' => $nom,
                'subtitle' => $fiche ? ($animaux[$fiche->id] ?? '') : '',
                'user_id' => $dernier->user_id,
                'client_id' => $fiche?->id,
                'client' => $nom,
                'peut_repondre' => $lie !== null,
                'non_lus' => $fil->where('auteur', 'proprietaire')->whereNull('lu_le')->count(),
                'dernier' => [
                    'texte' => mb_strimwidth($dernier->texte, 0, 140, '…'), 'auteur' => $dernier->auteur, 'is_mine' => $dernier->auteur === 'clinique', 'envoye_le' => $dernier->created_at?->toIso8601String(),
                    'status' => $dernier->auteur === 'clinique' ? MessageReceipts::status($dernier->delivered_at, $dernier->lu_le) : null,
                ],
            ];
        })->values()->all();
    }

    /** Un fil : ses messages, ceux du propriétaire marqués lus. */
    public function fil(string $cle): JsonResponse
    {
        $isMuted = $this->mutes->isMuted((int) request()->user()?->id, app(ContexteClinique::class)->cliniqueId(), $cle);
        if (in_array($cle[0], ['e', 'k'], true)) {
            return response()->json(['data' => [...$this->internes->thread($cle), 'muted' => $isMuted]]);
        }
        [$fiche, $userId, $lie] = $this->fiche($cle);
        // Lus : le propriétaire voit ses coches passer au bleu (08.11.2026)
        $this->receipts->read($this->messagesDu($cle, $fiche)->where('auteur', 'proprietaire'), 'lu_le', $userId !== null ? 'utilisateur.'.$userId : null, $userId);
        $messages = $this->messagesDu($cle, $fiche)->with('employe', 'media', 'attachments')->orderBy('created_at')->orderBy('id')->get();
        $paie = app(MessageMediaService::class)->clinicPays(Organisation::query()->find(app(ContexteClinique::class)->organisationId()));

        return response()->json(['data' => [
            'cle' => $cle,
            'kind' => 'client',
            'name' => trim($fiche->prenom.' '.$fiche->nom),
            'subtitle' => $fiche->patientsParNom()->limit(2)->pluck('nom')->implode(', '),
            'user_id' => $userId,
            'client' => trim($fiche->prenom.' '.$fiche->nom),
            'client_id' => $fiche->id,
            'peut_repondre' => $lie,
            // « Infos du client » (08.11.2026) : appeler, ses animaux, ses audios et vidéos, le silence
            'tel' => $fiche->tel,
            'animals' => $fiche->patientsParNom()->whereNull('decede_le')->get()->map(fn (Patient $p): array => [
                'id' => $p->id,
                'name' => $p->nom,
                'detail' => implode(', ', array_filter([$p->race ?: $p->espece->libelle(), mb_strtolower($p->sexe->value), AnimalProprietaireResource::age($p->ne_le)])),
            ])->all(),
            // Les audios, vidéos et pièces jointes du fil
            'media_count' => $messages->filter(fn (MessageClientele $m): bool => $m->media !== null)->count() + $messages->sum(fn (MessageClientele $m): int => $m->attachments->count()),
            'muted' => $isMuted,
            'messages' => $messages->map(fn (MessageClientele $m): array => $this->message($m, $paie))->all(),
        ]]);
    }

    /** Le mode silencieux d'une conversation, pour la personne connectée (08.11.2026) */
    public function silence(Request $request, string $cle): JsonResponse
    {
        $isMuted = (bool) $request->validate(['muted' => ['required', 'boolean']])['muted'];
        // Un fil d'ici seulement : sa lecture refuse ce qui n'existe pas dans cette clinique
        in_array($cle[0], ['e', 'k'], true) ? $this->internes->header($cle) : $this->fiche($cle);
        $userId = (int) $request->user()?->id;
        $this->mutes->set($userId, app(ContexteClinique::class)->cliniqueId(), $cle, $isMuted);
        // Ses autres appareils déjà ouverts relisent la liste (la pastille du menu suit)
        TempsReel::diffuser(fn () => MessagesChangent::dispatch('utilisateur.'.$userId));

        return response()->json(['data' => ['cle' => $cle, 'muted' => $isMuted]]);
    }

    /** Répondre, ou ouvrir un fil avec un client qui a un compte. */
    public function repondre(Request $request, string $cle): JsonResponse
    {
        $texte = trim((string) $request->validate(['texte' => ['required', 'string', 'max:2000']])['texte']);
        if (in_array($cle[0], ['e', 'k'], true)) {
            return response()->json(['data' => $this->internes->send($cle, $texte)], 201);
        }
        [$colonnes, $estPremier, $fiche] = $this->nouveauMessage($cle, 'texte');
        $message = MessageClientele::create([...$colonnes, 'texte' => $texte]);
        $this->publier($message, $fiche, $estPremier);

        return response()->json(['data' => $this->message($message->load('employe'))], 201);
    }

    /**
     * Un message audio ou une vidéo ronde (07.11.2026) : au client du fil, à
     * un collègue ou à une autre clinique (InternalMessaging).
     */
    public function repondreMedia(SendMessageMediaRequest $request, string $cle, MessageMediaService $media): JsonResponse
    {
        // Collègues et autres cliniques aussi (décision de Jim, 07.11.2026)
        if (in_array($cle[0], ['e', 'k'], true)) {
            return response()->json(['data' => $this->internes->sendMedia($cle, $request->file('file'), (string) $request->validated('kind'), $request->durationMs(), $request->validated('peaks'), $request->file('poster'))], 201);
        }
        [$colonnes, $estPremier, $fiche] = $this->nouveauMessage($cle, 'file');
        $message = $media->store($colonnes, $request->file('file'), (string) $request->validated('kind'), $request->durationMs(), $request->validated('peaks'), $request->file('poster'));
        $this->publier($message, $fiche, $estPremier);

        return response()->json(['data' => $this->message($message->load('employe'))], 201);
    }

    /**
     * Des pièces jointes (08.11.2026) : photos et PDF, au client du fil, à un
     * collègue ou à une autre clinique ; dans le fil d'un client, aussi des
     * documents du dossier de ses animaux (copiés : le message garde ce qui
     * a été envoyé, même si le document change ensuite).
     */
    public function replyWithAttachments(SendMessageAttachmentsRequest $request, string $cle, MessageAttachmentService $pieces): JsonResponse
    {
        $legende = $request->validated('texte');
        if (in_array($cle[0], ['e', 'k'], true)) {
            if ($request->documentIds() !== []) {
                throw ValidationException::withMessages(['document_ids' => 'Un document du dossier s’envoie seulement au client.']);
            }

            return response()->json(['data' => $this->internes->sendAttachments($cle, $request->readers(), $legende)], 201);
        }
        [$colonnes, $estPremier, $fiche] = $this->nouveauMessage($cle, 'files');
        $fichiers = [...$request->readers(), ...$this->recordDocuments($fiche, $request->documentIds(), $request)];
        $message = $pieces->storeWith(
            fn (string $texte): MessageClientele => MessageClientele::query()->create([...$colonnes, 'texte' => $texte]),
            'message_clientele_id', (int) $colonnes['clinique_id'], $fichiers, $legende,
        );
        $this->publier($message, $fiche, $estPremier);

        return response()->json(['data' => $this->message($message->load('employe'))], 201);
    }

    /**
     * Les documents du dossier des animaux de ce client, à joindre ; un
     * document d'un autre client, sans fichier, ou inconnu, est refusé.
     *
     * @param  list<int>  $ids
     * @return list<array{read: \Closure(): string, name: string}>
     */
    private function recordDocuments(Client $fiche, array $ids, SendMessageAttachmentsRequest $request): array
    {
        if ($ids === []) {
            return [];
        }
        // Un document dont le fichier manque sur le disque est refusé comme un document inconnu
        $documents = Document::query()->whereKey($ids)->whereNotNull('chemin')
            ->whereHas('patient', fn (Builder $patient) => $patient->where('client_id', $fiche->id))->get()
            ->filter(fn (Document $d): bool => Storage::disk('prive')->exists((string) $d->chemin))->keyBy('id');
        // Les documents comptent dans les 60 Mo du message, avec les fichiers envoyés
        $total = array_sum(array_map(fn ($f): int => (int) $f->getSize(), $request->uploads()))
            + $documents->sum(fn (Document $d): int => (int) Storage::disk('prive')->size((string) $d->chemin));
        if ($total > SendMessageAttachmentsRequest::maxTotalBytes()) {
            throw ValidationException::withMessages(['files' => SendMessageAttachmentsRequest::tooHeavy()]);
        }

        return array_map(function (int $id, int $i) use ($documents): array {
            $document = $documents[$id] ?? throw ValidationException::withMessages(['document_ids.'.$i => 'Ce document n’est pas dans le dossier de ce client.']);
            $chemin = (string) $document->chemin;

            return ['read' => fn (): string => (string) Storage::disk('prive')->get($chemin), 'name' => $document->nom.'.'.pathinfo($chemin, PATHINFO_EXTENSION)];
        }, $ids, array_keys($ids));
    }

    /**
     * Les colonnes d'un message de la clinique dans ce fil, si le client a
     * encore un compte lié ; et si c'est le premier du fil.
     *
     * @return array{0: array<string, mixed>, 1: bool, 2: Client}
     */
    private function nouveauMessage(string $cle, string $champ): array
    {
        [$fiche, $userId, $lie] = $this->fiche($cle);
        if (! $lie || $userId === null) {
            throw ValidationException::withMessages([$champ => 'Ce client n’a plus de compte lié : il ne lirait pas ce message. Appelez-le.']);
        }
        $contexte = app(ContexteClinique::class);
        // Le premier message du fil (la clinique écrit la première, 01.11.2026) : l'e-mail dit comment entrer dans l'espace
        $estPremier = ! MessageClientele::query()->where('clinique_id', $contexte->cliniqueId())->where('user_id', $userId)->exists();

        return [[
            'clinique_id' => $contexte->cliniqueId(),
            'user_id' => $userId,
            'client_id' => $fiche->id,
            'employe_id' => $contexte->employe()?->id,
            'auteur' => 'clinique',
        ], $estPremier, $fiche];
    }

    /** Le propriétaire est prévenu en temps réel, sur son téléphone, et par e-mail s'il l'a choisi ; la clinique aussi */
    private function publier(MessageClientele $message, Client $fiche, bool $estPremier): void
    {
        $contexte = app(ContexteClinique::class);
        $userId = (int) $message->user_id;
        TempsReel::diffuser(fn () => MessagesChangent::dispatch('utilisateur.'.$userId, $userId));
        TempsReel::diffuser(fn () => MessagesChangent::dispatch('clinique.'.$contexte->cliniqueId(), $userId));
        Push::to([$userId], PushType::ClinicMessage, $contexte->clinique()->nom, mb_strimwidth($message->texte, 0, 120, '…'), '/espace/messages');
        // Par e-mail aussi, s'il ne l'a pas coupé (« Réponses de la clinique »)
        $proprietaire = $fiche->user;
        if ($proprietaire !== null && PreferencesProprietaire::query()->where('user_id', $userId)->value('reponses_clinique') !== false) {
            // Resend en panne : la réponse est enregistrée et annoncée, l'erreur va au journal
            try {
                Mail::to($proprietaire->email)->send(new ReponseClinique($message, $contexte->clinique(), $estPremier));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * La fiche du fil, dans l'organisation (scope) : par le compte lié, ou
     * par la fiche gardée sur ses messages ; sinon, ce fil n'existe pas ici.
     *
     * @return array{0: Client, 1: ?int, 2: bool} fiche, compte, compte encore lié
     */
    private function fiche(string $cle): array
    {
        $id = (int) substr($cle, 1);
        if ($cle[0] === 'c') {
            return [Client::query()->findOrFail($id), null, false];
        }
        $lie = Client::query()->where('user_id', $id)->first();
        if ($lie !== null) {
            return [$lie, $id, true];
        }
        $cliniqueId = app(ContexteClinique::class)->cliniqueId();
        $ficheId = MessageClientele::query()->where('clinique_id', $cliniqueId)->where('user_id', $id)->whereNotNull('client_id')->value('client_id');

        return [Client::query()->findOrFail($ficheId ?? 0), $id, false];
    }

    /** @return Builder<MessageClientele> */
    private function messagesDu(string $cle, Client $fiche): Builder
    {
        $requete = MessageClientele::query()->where('clinique_id', app(ContexteClinique::class)->cliniqueId());

        return $cle[0] === 'c'
            ? $requete->whereNull('user_id')->where('client_id', $fiche->id)
            : $requete->where('user_id', (int) substr($cle, 1));
    }

    /**
     * Un message ; audio ou vidéo, son fichier (durée, onde) et sa
     * transcription, servis à l'équipe de la clinique.
     *
     * @return array<string, mixed>
     */
    private function message(MessageClientele $m, ?bool $paie = null): array
    {
        $media = $m->relationLoaded('media') ? $m->media : null;
        if ($media !== null && $paie === null) {
            $paie = app(MessageMediaService::class)->clinicPays(Organisation::query()->find(app(ContexteClinique::class)->organisationId()));
        }

        return [
            'id' => $m->id,
            'auteur' => $m->auteur,
            'is_mine' => $m->auteur === 'clinique',
            'signature' => $m->auteur === 'clinique' ? $m->employe?->nom : null,
            // Écrit par l'employé qui lit : sa bulle ne porte pas son nom, façon WhatsApp (09.11.2026)
            'is_by_me' => $m->auteur === 'clinique' && $m->employe_id !== null && $m->employe_id === app(ContexteClinique::class)->employe()?->id,
            'texte' => $m->texte,
            'envoye_le' => $m->created_at?->toIso8601String(),
            'lu_le' => $m->lu_le?->toIso8601String(),
            'status' => $m->auteur === 'clinique' ? MessageReceipts::status($m->delivered_at, $m->lu_le) : null,
            // Les pièces jointes, et la légende quand le texte n'est pas seulement ce qu'elles sont (08.11.2026)
            'attachments' => $m->relationLoaded('attachments') ? MessageAttachmentService::payload($m->attachments) : [],
            'caption' => $m->relationLoaded('attachments') ? MessageAttachmentService::caption($m->texte, $m->attachments) : null,
            'media' => $media ? MessageMediaService::forClinic($media, (bool) $paie) : null,
        ];
    }
}
