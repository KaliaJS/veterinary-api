<?php

namespace App\Http\Controllers\Api\V1\Videos;

use App\Auth\ContexteClinique;
use App\Enums\VideoAudience;
use App\Enums\VideoCategory;
use App\Enums\VideoFilesStatus;
use App\Enums\VideoKind;
use App\Enums\VideoStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Videos\StoreVideoPosterRequest;
use App\Http\Requests\V1\Videos\StoreVideoRequest;
use App\Http\Requests\V1\Videos\UpdateVideoRequest;
use App\Http\Resources\V1\VideoResource;
use App\Jobs\AssembleVideoDownload;
use App\Jobs\PurgeVideoFolder;
use App\Jobs\RecheckVideoFiles;
use App\Models\Video;
use App\Services\Videos\HlsPackage;
use App\Services\Videos\Sellers;
use App\Services\Videos\SellerTerms;
use App\Services\Videos\VideoPosters;
use App\Services\Videos\VideoPublisher;
use App\Services\Videos\VideoStorage;
use App\Support\NativeApp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Les vidéos d'un vendeur (31.10.2026) : brouillons, mise en vente,
 * retrait. En son nom (espace du compte) ou au nom de la clinique du jeton
 * (espace clinique, droit organisation) : voir les deux classes filles.
 */
abstract class SellerVideoController extends Controller
{
    /** 'me' : en son nom ; 'clinic' : au nom de la clinique du jeton. */
    protected string $as = 'me';

    public function __construct(
        protected Sellers $sellers,
        protected VideoStorage $storage,
        protected ContexteClinique $context,
    ) {}

    /** @return Builder<Video> */
    protected function scope(Request $request): Builder
    {
        return $this->as === 'me'
            ? Video::query()->where('seller_user_id', $request->user()->id)
            : Video::query()->where('seller_clinique_id', $this->context->cliniqueId());
    }

    protected function find(Request $request, string $uuid): Video
    {
        return $this->scope($request)->where('uuid', $uuid)->firstOrFail();
    }

    public function index(Request $request): JsonResponse
    {
        $videos = $this->scope($request)->withCount(['purchases as sales_count' => fn ($purchase) => $purchase->whereNotNull('paid_at')])->latest('id')->get();

        return response()->json([
            'data' => $videos->map(fn (Video $video) => VideoResource::seenBy($video, 'seller', true)->resolve($request))->all(),
            // Pour l'écran : peut-il vendre, et la place qui reste
            'meta' => [
                'verified' => $request->user()->estVerifie(),
                // La liste fixe des catégories, dans l'ordre des rangées du catalogue
                'categories' => VideoCategory::options(),
                'storage' => $this->sellers->storageFor($request->user(), $this->as),
                // Les conditions de vente : la version en vigueur, et si elle est acceptée
                'terms' => ['version' => SellerTerms::VERSION, 'accepted' => app(SellerTerms::class)->acceptedFor($request->user(), $this->as)],
            ],
        ]);
    }

    /**
     * Un brouillon : une vidéo seule, une série, ou un épisode d'une de ses
     * séries (numéroté à la suite, vendu par le même vendeur que la série).
     */
    public function store(StoreVideoRequest $request): JsonResponse
    {
        $seller = $this->sellers->resolve($request->user(), $this->as);
        $fields = [...$seller, 'created_by_user_id' => $request->user()->id, 'title' => $request->validated('title')];
        if ($request->filled('series')) {
            $series = $this->find($request, (string) $request->validated('series'));
            abort_unless($series->isSeries(), 404);
            $this->sellers->assertCanManage($series, $request->user(), $this->as);
            $video = DB::transaction(function () use ($series, $fields): Video {
                $last = (int) Video::query()->where('series_id', $series->id)->lockForUpdate()->max('episode_number');

                return Video::create([...$fields, 'kind' => VideoKind::Episode, 'series_id' => $series->id, 'episode_number' => $last + 1]);
            });
        } else {
            $video = Video::create([...$fields, 'kind' => $request->validated('kind') === 'series' ? VideoKind::Series : VideoKind::Video]);
        }

        return VideoResource::seenBy($video->refresh(), 'seller', true)->response()->setStatusCode(201);
    }

    /**
     * L'ordre des épisodes d'une série (les flèches de l'écran) : tous ses
     * épisodes, dans le nouvel ordre ; aussi pour une série en vente.
     */
    public function reorder(Request $request, string $video): VideoResource
    {
        $series = $this->find($request, $video);
        abort_unless($series->isSeries(), 404);
        $this->sellers->assertCanManage($series, $request->user(), $this->as);
        $ids = $request->validate(['episodes' => ['required', 'array'], 'episodes.*' => ['uuid']])['episodes'];
        DB::transaction(function () use ($series, $ids): void {
            $episodes = Video::query()->where('series_id', $series->id)->lockForUpdate()->get()->keyBy('uuid');
            if (count($ids) !== $episodes->count() || array_diff($ids, $episodes->keys()->all()) !== [] || count(array_unique($ids)) !== count($ids)) {
                throw ValidationException::withMessages(['episodes' => 'La liste des épisodes vient de changer : rechargez la page, puis recommencez.']);
            }
            foreach (array_values($ids) as $index => $uuid) {
                Video::query()->whereKey($episodes[$uuid]->id)->update(['episode_number' => $index + 1, 'updated_at' => now()]);
            }
        });

        return VideoResource::seenBy($series->refresh(), 'seller', true);
    }

    public function update(UpdateVideoRequest $request, string $video): VideoResource
    {
        $model = $this->find($request, $video);
        $this->sellers->assertCanManage($model, $request->user(), $this->as);
        $data = $request->validated();
        // Un épisode n'a que son titre et sa description : le prix, le public, la catégorie et Plus sont à sa série
        if ($model->isEpisode()) {
            $foreign = array_values(array_intersect(array_keys($data), ['price_cents', 'audience', 'category', 'in_plus']));
            if ($foreign !== []) {
                throw ValidationException::withMessages(array_fill_keys($foreign, 'Le prix, le public, la catégorie et Plus se règlent sur la série.'));
            }
        }

        // Dans l'app iPhone, rien ne se met en vente : une vidéo en vente ne s'y modifie pas non plus
        if ($model->status === VideoStatus::Published) {
            NativeApp::refuse($request, 'Une vidéo en vente se modifie sur le site de Veterinary, pas dans l’app iPhone.');
        }
        // En vente, ce qui la décrit ne s'efface pas
        if ($model->status === VideoStatus::Published) {
            $blank = array_filter(['title', 'description', 'price_cents', 'audience', 'category'], fn (string $key) => array_key_exists($key, $data) && blank($data[$key]));
            if ($blank !== []) {
                throw ValidationException::withMessages(array_fill_keys($blank, 'Une vidéo en vente garde son titre, sa description, son prix, son public et sa catégorie.'));
            }
        }

        // Une vidéo gratuite se regarde déjà par tous : elle n'entre pas dans Plus (et en sort si elle le devient)
        $price = array_key_exists('price_cents', $data) ? $data['price_cents'] : $model->price_cents;
        if ($price !== null && (int) $price === 0) {
            if ($data['in_plus'] ?? false) {
                throw ValidationException::withMessages(['in_plus' => 'Une vidéo gratuite se regarde déjà par tous : elle n’entre pas dans Plus.']);
            }
            if (! $model->isEpisode()) {
                $data['in_plus'] = false;
            }
        }
        // Plus n'inclut que des vidéos destinées aux clients
        $audience = array_key_exists('audience', $data) ? VideoAudience::tryFrom((string) $data['audience']) : $model->audience;
        if (! $model->isEpisode() && ! ($audience?->includesOwners() ?? false)) {
            if ($data['in_plus'] ?? false) {
                throw ValidationException::withMessages(['in_plus' => 'Plus n’inclut que des vidéos destinées aux clients.']);
            }
            $data['in_plus'] = false;
        }
        // Ce qu'une vidéo en vente doit garder, vidé : seulement si elle n'est toujours pas en vente au moment
        // de l'écriture (une mise en vente dans un autre onglet l'emporte, la fiche reste complète)
        $blanked = array_filter(['title', 'description', 'price_cents', 'audience', 'category'], fn (string $key) => array_key_exists($key, $data) && blank($data[$key]));
        if ($blanked !== []) {
            $written = Video::query()->whereKey($model->id)->where('status', '!=', VideoStatus::Published->value)
                ->update([...$data, 'updated_at' => now()]);
            if ($written === 0) {
                abort_unless(Video::query()->whereKey($model->id)->exists(), 404);
                throw ValidationException::withMessages(array_fill_keys(array_values($blanked), 'Une vidéo en vente garde son titre, sa description, son prix, son public et sa catégorie.'));
            }

            return VideoResource::seenBy($model->refresh(), 'seller', true);
        }
        // Effacée entre-temps (autre onglet) : introuvable, jamais un « enregistré » sur une fiche qui n'existe plus
        $written = Video::query()->whereKey($model->id)->update([...$data, 'updated_at' => now()]);
        abort_if($written === 0, 404);

        return VideoResource::seenBy($model->refresh(), 'seller', true);
    }

    /** Seul un brouillon s'efface ; une vidéo vendue se retire de la vente, ses acheteurs la gardent. */
    public function destroy(Request $request, string $video): Response
    {
        $model = $this->find($request, $video);
        $this->sellers->assertCanManage($model, $request->user(), $this->as);
        // Un épisode d'une série jamais vendue s'efface, même déjà ajouté ; d'une série vendue, jamais
        if ($model->isEpisode()) {
            if ($model->hasBuyers()) {
                throw ValidationException::withMessages(['status' => 'Cette série a été achetée : ses épisodes restent à ceux qui l’ont achetée.']);
            }
            $this->eraseEpisode($model);

            return response()->noContent();
        }
        // Une vidéo ou une série qui a été en vente ne s'efface jamais : achats et certificats en dépendent
        if ($model->status !== VideoStatus::Draft || $model->published_at !== null) {
            throw ValidationException::withMessages(['status' => $model->isSeries()
                ? 'Une série mise en vente ne s’efface pas : retirez-la de la vente, ceux qui l’ont achetée la gardent.'
                : 'Une vidéo mise en vente ne s’efface pas : retirez-la de la vente, ceux qui l’ont achetée la gardent.']);
        }
        // Une série en brouillon part avec ses épisodes
        if ($model->isSeries()) {
            foreach (Video::query()->where('series_id', $model->id)->get() as $episode) {
                $this->eraseEpisode($episode);
            }
        }
        // Tout sous le verrou de l'affiche : celle qu'un autre onglet vient de poser part avec le brouillon,
        // et un geste trop long ailleurs arrête l'effacement avant qu'il commence (rien à moitié)
        VideoPosters::exclusively($model->id, function () use ($model): void {
            // Retenu d'abord, d'une seule écriture : toujours un brouillon jamais vendu, ses fichiers ne sont plus
            // « prêts », et une mise en vente lancée au même moment (qui exige des fichiers prêts) ne passe plus
            $claimed = Video::query()->whereKey($model->id)->where('status', VideoStatus::Draft->value)->whereNull('published_at')
                ->update(['files_status' => VideoFilesStatus::Rejected->value, 'publish_requested_at' => null, 'publish_requested_by' => null, 'updated_at' => now()]);
            if ($claimed === 0) {
                abort_unless(Video::query()->whereKey($model->id)->exists(), 404);
                throw ValidationException::withMessages(['status' => 'Une vidéo mise en vente ne s’efface pas : retirez-la de la vente, ceux qui l’ont achetée la gardent.']);
            }
            $this->storage->deleteAll($model);
            // Des adresses d'envoi encore valables écriraient dans le dossier : on l'efface encore une fois expirées
            // (toujours : le guet a pu oublier l'envoi en le refusant, ses adresses écrivent encore)
            PurgeVideoFolder::dispatch($model->folder())->delay(now()->addMinutes(SellerVideoUploadController::URL_MINUTES + 1));
            // Un brouillon ne se vend pas : seules les lectures du vendeur s'y rattachent
            $model->views()->delete();
            $model->certificates()->delete();
            $poster = Video::query()->whereKey($model->id)->value('poster_token');
            $model->delete();
            app(VideoPosters::class)->delete($poster);
        });

        return response()->noContent();
    }

    /**
     * Un épisode effacé (série jamais vendue) : ses fichiers, ses lectures, son
     * image, puis la numérotation des autres se resserre. Sous le verrou de
     * l'image, comme un brouillon.
     */
    private function eraseEpisode(Video $episode): void
    {
        VideoPosters::exclusively($episode->id, function () use ($episode): void {
            $current = Video::query()->whereKey($episode->id)->first(['id', 'poster_token', 'series_id']);
            abort_if($current === null, 404);
            $this->storage->deleteAll($episode);
            PurgeVideoFolder::dispatch($episode->folder())->delay(now()->addMinutes(SellerVideoUploadController::URL_MINUTES + 1));
            $episode->views()->delete();
            $episode->certificates()->delete();
            $episode->delete();
            app(VideoPosters::class)->delete($current->poster_token);
            $remaining = Video::query()->where('series_id', $current->series_id)->orderBy('episode_number')->orderBy('id')->pluck('id');
            foreach ($remaining->values() as $index => $id) {
                Video::query()->whereKey($id)->update(['episode_number' => $index + 1]);
            }
        });
    }

    /**
     * L'affiche (31.10.2026) : une image de la vidéo, tirée par le navigateur
     * du vendeur, ou une photo. Elle remplace la précédente.
     */
    public function poster(StoreVideoPosterRequest $request, string $video, VideoPosters $posters): VideoResource
    {
        $model = $this->find($request, $video);
        $this->sellers->assertCanManage($model, $request->user(), $this->as);
        if ($model->status === VideoStatus::Published) {
            NativeApp::refuse($request, 'Une vidéo en vente se modifie sur le site de Veterinary, pas dans l’app iPhone.');
        }
        // Un geste à la fois sur l'affiche, relue sous le verrou : deux envois croisés n'oublient aucun dossier,
        // un brouillon effacé entre-temps n'en reçoit pas. Prise d'elle-même au montage (only_if_none) :
        // jamais à la place d'une affiche déjà choisie
        $onlyIfNone = $request->boolean('only_if_none');
        VideoPosters::exclusively($model->id, function () use ($request, $model, $posters, $onlyIfNone): void {
            $current = Video::query()->whereKey($model->id)->first(['id', 'poster_token']);
            abort_if($current === null, 404);
            if ($onlyIfNone && $current->poster_token !== null) {
                return;
            }
            $stored = $posters->store($request->file('poster'));
            Video::query()->whereKey($model->id)->update(['poster_token' => $stored['token'], 'poster_preview' => $stored['preview'], 'updated_at' => now()]);
            $posters->delete($current->poster_token);
        });

        return VideoResource::seenBy($model->refresh(), 'seller', true);
    }

    /** Sans affiche : seulement hors de la vente, où chaque vidéo en a une. */
    public function removePoster(Request $request, string $video, VideoPosters $posters): VideoResource
    {
        $model = $this->find($request, $video);
        $this->sellers->assertCanManage($model, $request->user(), $this->as);
        if ($model->status === VideoStatus::Published) {
            throw ValidationException::withMessages(['poster' => 'Une vidéo en vente garde son affiche : remplacez-la plutôt.']);
        }
        VideoPosters::exclusively($model->id, function () use ($model, $posters): void {
            $current = Video::query()->whereKey($model->id)->first(['id', 'poster_token']);
            abort_if($current === null, 404);
            $previous = $current->poster_token;
            // Seulement hors de la vente, au moment même de l'écriture : une mise en vente faite entre-temps l'emporte
            $removed = Video::query()->whereKey($model->id)->where('status', '!=', VideoStatus::Published->value)
                ->update(['poster_token' => null, 'poster_preview' => null, 'updated_at' => now()]);
            if ($removed === 0) {
                throw ValidationException::withMessages(['poster' => 'Une vidéo en vente garde son affiche : remplacez-la plutôt.']);
            }
            $posters->delete($previous);
        });

        return VideoResource::seenBy($model->refresh(), 'seller', true);
    }

    /** Mise en vente immédiate, sans validation à la main ; jamais depuis l'app iPhone. */
    public function publish(Request $request, string $video, VideoPublisher $publisher, SellerTerms $terms): VideoResource|JsonResponse
    {
        NativeApp::refuse($request, 'La mise en vente se fait sur le site de Veterinary, pas dans l’app iPhone.');
        $model = $this->find($request, $video);
        $this->sellers->assertCanManage($model, $request->user(), $this->as);
        // Les conditions de vente acceptées une fois (et de nouveau quand elles changent), cochées à la mise en vente
        if (! $terms->isAccepted($model)) {
            if (! $request->boolean('accept_terms')) {
                throw ValidationException::withMessages(['terms' => 'Pour mettre en vente, acceptez les conditions de vente des vidéos de formation.']);
            }
            $terms->accept($model, $request->user());
        }

        // Une série n'a pas de fichiers : elle part avec ses épisodes prêts
        if ($model->isSeries()) {
            $publisher->publish($model, $this->sellers->resolve($request->user(), $this->as)['seller_name']);

            return VideoResource::seenBy($model, 'seller', true);
        }
        if ($model->files_status !== VideoFilesStatus::Ready) {
            throw ValidationException::withMessages(['files' => 'La vidéo n’est pas encore prête : terminez son envoi.']);
        }
        $missing = $publisher->missing($model);
        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
        if ($model->isEpisode() && $model->series?->status !== VideoStatus::Published) {
            throw ValidationException::withMessages(['series' => 'La série n’est pas en vente : mettez la série en vente, ses épisodes prêts partent avec elle.']);
        }
        $this->sellers->stripeAccount($model);
        $sellerName = $this->sellers->resolve($request->user(), $this->as)['seller_name'];

        // Chaque morceau relu avant la vente : pas encore fait, la vidéo se mettra en vente d'elle-même ensuite
        if ($model->files_checked_at === null) {
            // Seulement sur les fichiers lus ici, toujours prêts : un nouvel envoi lancé dans un autre onglet ne s'en hérite pas
            $requested = Video::query()->whereKey($model->id)
                ->where('files_status', VideoFilesStatus::Ready->value)
                ->where('upload_started_at', $model->upload_started_at)
                ->update(['publish_requested_at' => now(), 'publish_requested_by' => $request->user()->id, 'seller_name' => $sellerName, 'updated_at' => now()]);
            if ($requested === 0) {
                throw ValidationException::withMessages(['files' => 'La vidéo change de fichiers : mettez-la en vente une fois le nouvel envoi contrôlé.']);
            }
            // Relue entre-temps sans avoir vu la demande : on ne l'attend pas, la mise en vente se fait ici
            if ($model->refresh()->files_checked_at === null) {
                RecheckVideoFiles::dispatch($model->id, 'now', $model->uploadStamp());

                return VideoResource::seenBy($model, 'seller', true)->response()->setStatusCode(202);
            }
        }
        // Relue avant d'être vendue : rien n'a été remplacé depuis le contrôle
        $check = app(HlsPackage::class)->inspect($model, [AssembleVideoDownload::FILE]);
        if ($check['ok'] === false && ! empty($check['transient'])) {
            abort(503, $check['reason']);
        }
        if ($check['ok'] === false) {
            // Achetée : jamais effacée, ceux qui l'ont payée la gardent ; elle reste seulement hors de la vente
            if (! $model->hasBuyers()) {
                $this->storage->deleteAll($model);
                // Repart en brouillon (même retirée) : le vendeur peut la renvoyer
                $model->update([
                    'files_status' => VideoFilesStatus::Rejected, 'rejection_reason' => $check['reason'], 'size_bytes' => 0, 'renditions' => null, 'download_path' => null,
                    'status' => VideoStatus::Draft, 'publish_requested_at' => null, 'publish_requested_by' => null,
                ]);
            }
            throw ValidationException::withMessages(['files' => $check['reason']]);
        }
        $publisher->publish($model, $sellerName);

        return VideoResource::seenBy($model, 'seller', true);
    }

    /** Retirée de la vente : plus de nouvel acheteur, ceux qui l'ont achetée la gardent à vie. */
    public function withdraw(Request $request, string $video): VideoResource
    {
        $model = $this->find($request, $video);
        // Retirer reste possible à qui ne peut plus vendre (vérification perdue)
        $this->sellers->assertOwns($model, $this->as);
        if ($model->isEpisode()) {
            throw ValidationException::withMessages(['status' => 'Un épisode ne se retire pas seul : retirez la série, ceux qui l’ont achetée la gardent entière.']);
        }
        if ($model->status !== VideoStatus::Published) {
            throw ValidationException::withMessages(['status' => 'Cette vidéo n’est pas en vente.']);
        }
        $model->update(['status' => VideoStatus::Withdrawn, 'withdrawn_at' => now()]);

        return VideoResource::seenBy($model, 'seller', true);
    }
}
