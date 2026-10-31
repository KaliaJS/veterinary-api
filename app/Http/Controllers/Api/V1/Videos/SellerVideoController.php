<?php

namespace App\Http\Controllers\Api\V1\Videos;

use App\Auth\ContexteClinique;
use App\Enums\VideoAudience;
use App\Enums\VideoFilesStatus;
use App\Enums\VideoStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Videos\StoreVideoRequest;
use App\Http\Requests\V1\Videos\UpdateVideoRequest;
use App\Http\Resources\V1\VideoResource;
use App\Jobs\AssembleVideoDownload;
use App\Jobs\RecheckVideoFiles;
use App\Models\Video;
use App\Paiements\PasserellePaiement;
use App\Services\Videos\HlsPackage;
use App\Services\Videos\Sellers;
use App\Services\Videos\VideoStorage;
use App\Support\NativeApp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Throwable;

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
                'storage' => $this->sellers->storageFor($request->user(), $this->as),
            ],
        ]);
    }

    public function store(StoreVideoRequest $request): JsonResponse
    {
        $seller = $this->sellers->resolve($request->user(), $this->as);
        $video = Video::create([...$seller, 'created_by_user_id' => $request->user()->id, 'title' => $request->validated('title')]);

        return VideoResource::seenBy($video->refresh(), 'seller', true)->response()->setStatusCode(201);
    }

    public function update(UpdateVideoRequest $request, string $video): VideoResource
    {
        $model = $this->find($request, $video);
        $this->sellers->assertCanManage($model, $request->user(), $this->as);
        $data = $request->validated();

        // Dans l'app iPhone, rien ne se met en vente : une vidéo en vente ne s'y modifie pas non plus
        if ($model->status === VideoStatus::Published) {
            NativeApp::refuse($request, 'Une vidéo en vente se modifie sur le site de Veterinary, pas dans l’app iPhone.');
        }
        // En vente, ce qui la décrit ne s'efface pas
        if ($model->status === VideoStatus::Published) {
            $blank = array_filter(['title', 'description', 'price_cents', 'audience'], fn (string $key) => array_key_exists($key, $data) && blank($data[$key]));
            if ($blank !== []) {
                throw ValidationException::withMessages(array_fill_keys($blank, 'Une vidéo en vente garde son titre, sa description, son prix et son public.'));
            }
        }

        // Plus n'inclut que des vidéos destinées aux clients
        $audience = array_key_exists('audience', $data) ? VideoAudience::tryFrom((string) $data['audience']) : $model->audience;
        if (! ($audience?->includesOwners() ?? false)) {
            if ($data['in_plus'] ?? false) {
                throw ValidationException::withMessages(['in_plus' => 'Plus n’inclut que des vidéos destinées aux clients.']);
            }
            $data['in_plus'] = false;
        }
        $model->update($data);

        return VideoResource::seenBy($model, 'seller', true);
    }

    /** Seul un brouillon s'efface ; une vidéo vendue se retire de la vente, ses acheteurs la gardent. */
    public function destroy(Request $request, string $video): Response
    {
        $model = $this->find($request, $video);
        $this->sellers->assertCanManage($model, $request->user(), $this->as);
        // Une vidéo qui a été en vente ne s'efface jamais : achats et certificats en dépendent
        if ($model->status !== VideoStatus::Draft || $model->published_at !== null) {
            throw ValidationException::withMessages(['status' => 'Une vidéo mise en vente ne s’efface pas : retirez-la de la vente, ceux qui l’ont achetée la gardent.']);
        }
        $this->storage->deleteAll($model);
        // Un brouillon ne se vend pas : seules les lectures du vendeur s'y rattachent
        $model->views()->delete();
        $model->certificates()->delete();
        $model->delete();

        return response()->noContent();
    }

    /** Mise en vente immédiate, sans validation à la main ; jamais depuis l'app iPhone. */
    public function publish(Request $request, string $video): VideoResource
    {
        NativeApp::refuse($request, 'La mise en vente se fait sur le site de Veterinary, pas dans l’app iPhone.');
        $model = $this->find($request, $video);
        $this->sellers->assertCanManage($model, $request->user(), $this->as);

        if ($model->files_status !== VideoFilesStatus::Ready) {
            throw ValidationException::withMessages(['files' => 'La vidéo n’est pas encore prête : terminez son envoi.']);
        }
        // Chaque morceau relu avant la vente ; pas encore fait (ou interrompu par une panne) : on relance
        if ($model->files_checked_at === null) {
            RecheckVideoFiles::dispatch($model->id);
            throw ValidationException::withMessages(['files' => 'La vidéo se relit encore en entier : elle se met en vente dans un instant, réessayez.']);
        }
        // Relue avant d'être vendue : rien n'a été remplacé depuis le contrôle
        $check = app(HlsPackage::class)->inspect($model, [AssembleVideoDownload::FILE]);
        if ($check['ok'] === false && ! empty($check['transient'])) {
            abort(503, $check['reason']);
        }
        if ($check['ok'] === false) {
            $this->storage->deleteAll($model);
            $model->update(['files_status' => VideoFilesStatus::Rejected, 'rejection_reason' => $check['reason'], 'size_bytes' => 0, 'renditions' => null, 'download_path' => null]);
            throw ValidationException::withMessages(['files' => $check['reason']]);
        }
        $missing = array_filter([
            'title' => blank($model->title) ? 'Donnez un titre à la vidéo.' : null,
            'description' => blank($model->description) ? 'Décrivez la vidéo.' : null,
            'price_cents' => $model->price_cents === null ? 'Fixez son prix : 2 CHF au moins.' : null,
            'audience' => $model->audience === null ? 'Choisissez à qui elle est destinée.' : null,
        ]);
        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
        // Les ventes encaissent sur un compte prêt
        $account = $this->sellers->stripeAccount($model);
        // La cagnotte Plus se verse par virement : les comptes ouverts avant les vidéos doivent pouvoir en recevoir
        if ($model->in_plus) {
            try {
                app(PasserellePaiement::class)->pourCompte(null)->demanderVirementsRecus($account);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $model->update([
            'status' => VideoStatus::Published,
            'published_at' => $model->published_at ?? now(),
            'withdrawn_at' => null,
            'seller_name' => $this->sellers->resolve($request->user(), $this->as)['seller_name'],
        ]);

        return VideoResource::seenBy($model, 'seller', true);
    }

    /** Retirée de la vente : plus de nouvel acheteur, ceux qui l'ont achetée la gardent à vie. */
    public function withdraw(Request $request, string $video): VideoResource
    {
        $model = $this->find($request, $video);
        // Retirer reste possible à qui ne peut plus vendre (vérification perdue)
        $this->sellers->assertOwns($model, $this->as);
        if ($model->status !== VideoStatus::Published) {
            throw ValidationException::withMessages(['status' => 'Cette vidéo n’est pas en vente.']);
        }
        $model->update(['status' => VideoStatus::Withdrawn, 'withdrawn_at' => now()]);

        return VideoResource::seenBy($model, 'seller', true);
    }
}
