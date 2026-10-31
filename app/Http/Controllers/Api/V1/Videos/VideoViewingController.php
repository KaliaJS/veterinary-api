<?php

namespace App\Http\Controllers\Api\V1\Videos;

use App\Enums\VideoFilesStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Videos\ProgressRequest;
use App\Models\Video;
use App\Models\ViewingCertificate;
use App\Services\Videos\VideoAccess;
use App\Services\Videos\VideoPlayback;
use App\Services\Videos\VideoStorage;
use App\Services\Videos\VideoViews;
use App\Services\Videos\ViewingCertificates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Regarder (31.10.2026) : en entier pour le vendeur, l'acheteur ou
 * l'abonné Plus d'une vidéo incluse ; sinon la première minute. Les
 * secondes regardées comptent les vues et ouvrent le certificat ; un
 * acheteur télécharge la version 720p.
 */
class VideoViewingController extends Controller
{
    public function __construct(
        private VideoAccess $access,
        private VideoPlayback $playback,
        private VideoViews $views,
        private VideoStorage $storage,
    ) {}

    /** @return array{0: Video, 1: string} */
    private function watchable(Request $request, string $uuid): array
    {
        $video = Video::query()->where('uuid', $uuid)->firstOrFail();
        $user = $request->user();
        $access = $this->access->for($video, $user, $video->isOnSellerSide($user));
        abort_if($access === null || $video->files_status !== VideoFilesStatus::Ready, 404);

        return [$video, $access];
    }

    public function play(Request $request, string $video): JsonResponse
    {
        [$model, $access] = $this->watchable($request, $video);
        $scope = $access === 'preview' ? 'preview' : 'full';
        $this->views->start($model, $request->user());

        return response()->json(['data' => ['url' => $this->playback->masterUrl($model, $scope), 'scope' => $scope, 'access' => $access]]);
    }

    public function progress(ProgressRequest $request, string $video): JsonResponse
    {
        [$model, $access] = $this->watchable($request, $video);
        $view = $this->views->record($model, $request->user(), $request->validated('ranges'), $access);

        return response()->json(['data' => [
            'watched_seconds' => $view->watched_seconds,
            'counted' => $view->counted_at !== null,
            'completed' => $view->completed_at !== null,
        ]]);
    }

    /** La version 720p, pour l'acheteur seulement. */
    public function download(Request $request, string $video): JsonResponse
    {
        [$model, $access] = $this->watchable($request, $video);
        abort_unless($access === 'owned' && $model->download_path !== null, 404);
        // Nom du fichier en ASCII : l'en-tête Content-Disposition du bucket n'accepte rien d'autre
        $name = (Str::slug((string) $model->title) ?: 'video').'.mp4';

        return response()->json(['data' => ['url' => $this->storage->readUrl($model, $model->download_path, now()->addHour(), $name)]]);
    }

    /** Le certificat de visionnage, une fois la vidéo regardée en entier. */
    public function certificate(Request $request, string $video, ViewingCertificates $certificates): JsonResponse
    {
        // Acquis par le visionnage : même retirée, ou sortie de Plus depuis, la vidéo regardée en entier le donne
        $model = Video::query()->where('uuid', $video)->firstOrFail();

        return response()->json(['data' => $this->certificateData($certificates->issue($model, $request->user()))]);
    }

    /** Page publique : ce qu'atteste un certificat, d'après son code. */
    public function verify(string $code): JsonResponse
    {
        $certificate = ViewingCertificate::query()->where('code', ViewingCertificates::normalize($code))->first();
        abort_if($certificate === null, 404, 'Aucun certificat ne porte ce code.');

        return response()->json(['data' => $this->certificateData($certificate)]);
    }

    /** @return array<string, mixed> */
    private function certificateData(ViewingCertificate $certificate): array
    {
        return [
            'code' => $certificate->code,
            'holder_name' => $certificate->holder_name,
            'holder_gln' => $certificate->holder_gln,
            'video_title' => $certificate->video_title,
            'seller_name' => $certificate->seller_name,
            'duration_seconds' => (float) $certificate->duration_seconds,
            'watched_at' => $certificate->watched_at->toIso8601String(),
        ];
    }

    /** Liste maîtresse signée (Safari la lit sans jeton). */
    public function master(Request $request, string $video): Response
    {
        $model = Video::query()->where('uuid', $video)->where('files_status', VideoFilesStatus::Ready->value)->firstOrFail();
        $scope = $request->query('scope') === 'full' ? 'full' : 'preview';

        return response($this->playback->master($model, $scope, (int) $request->query('expires')), 200, ['Content-Type' => 'application/vnd.apple.mpegurl', 'Cache-Control' => 'private, no-store']);
    }

    /** Liste d'une qualité, signée. */
    public function media(Request $request, string $video, string $name): Response
    {
        $model = Video::query()->where('uuid', $video)->where('files_status', VideoFilesStatus::Ready->value)->firstOrFail();
        $scope = $request->query('scope') === 'full' ? 'full' : 'preview';
        $text = $this->playback->media($model, $name, $scope, (int) $request->query('expires'));
        abort_if($text === null, 404);

        return response($text, 200, ['Content-Type' => 'application/vnd.apple.mpegurl', 'Cache-Control' => 'private, no-store']);
    }
}
