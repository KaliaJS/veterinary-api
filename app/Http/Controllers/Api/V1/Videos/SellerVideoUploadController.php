<?php

namespace App\Http\Controllers\Api\V1\Videos;

use App\Enums\VideoFilesStatus;
use App\Enums\VideoStatus;
use App\Http\Requests\V1\Videos\StartUploadRequest;
use App\Http\Requests\V1\Videos\UploadUrlsRequest;
use App\Http\Resources\V1\VideoResource;
use App\Jobs\AssembleVideoDownload;
use App\Models\Video;
use App\Services\Videos\HlsPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * L'envoi des fichiers d'une vidéo (31.10.2026), directement du navigateur
 * au bucket : le serveur signe, fichier par fichier, une adresse qui ne
 * permet que de déposer ce fichier, pendant 15 minutes. Puis il contrôle
 * le paquet reçu avant toute mise en vente.
 */
abstract class SellerVideoUploadController extends SellerVideoController
{
    /** Durée de validité d'une adresse d'envoi. */
    private const URL_MINUTES = 15;

    /** Avant d'envoyer : la durée et la taille estimée, refusées avant de commencer si elles dépassent. */
    public function start(StartUploadRequest $request, string $video): JsonResponse
    {
        $model = $this->editable($request, $video);
        $storage = $this->sellers->storage($model);
        $after = $storage['used'] - (int) $model->size_bytes + (int) $request->validated('estimated_bytes');
        if ($after > $storage['limit']) {
            throw ValidationException::withMessages(['estimated_bytes' => 'Votre stockage est plein : cette vidéo ne tient pas dans ce qui reste.']);
        }

        // Un nouvel envoi remplace les fichiers d'avant
        $this->storage->deleteAll($model);
        $model->update([
            'files_status' => VideoFilesStatus::Pending, 'rejection_reason' => null, 'size_bytes' => 0,
            'duration_seconds' => null, 'width' => null, 'height' => null, 'renditions' => null,
            'download_path' => null, 'files_verified_at' => null,
        ]);

        return response()->json(['data' => ['url_minutes' => self::URL_MINUTES]]);
    }

    /** Les adresses d'envoi des fichiers demandés. */
    public function urls(UploadUrlsRequest $request, string $video): JsonResponse
    {
        $model = $this->editable($request, $video);
        $expires = now()->addMinutes(self::URL_MINUTES);

        return response()->json(['data' => array_map(fn (string $name) => [
            'name' => $name,
            'url' => $this->storage->uploadUrl($model, $name, $expires),
            'content_type' => $this->storage::contentType($name),
        ], array_values(array_unique($request->validated('files'))))]);
    }

    /** Ce qui est déjà arrivé : un envoi interrompu reprend là où il s'est arrêté. */
    public function files(Request $request, string $video): JsonResponse
    {
        $model = $this->find($request, $video);
        $this->sellers->assertCanManage($model, $request->user(), $this->as);

        return response()->json(['data' => (object) $this->storage->files($model)]);
    }

    /** Le paquet reçu, contrôlé : prêt à vendre, ou refusé (et effacé) avec sa raison. */
    public function finish(Request $request, string $video): VideoResource|JsonResponse
    {
        $model = $this->editable($request, $video);
        $result = app(HlsPackage::class)->inspect($model);
        if ($result['ok'] === false) {
            return $this->reject($model, $result['reason']);
        }

        $storage = $this->sellers->storage($model);
        if ($storage['limit'] < $storage['used'] - (int) $model->size_bytes + $result['size']) {
            return $this->reject($model, 'Votre stockage est plein : la vidéo a été effacée.');
        }

        $model->update([
            'files_status' => VideoFilesStatus::Ready,
            'rejection_reason' => null,
            'duration_seconds' => $result['duration'],
            'width' => $result['width'],
            'height' => $result['height'],
            'size_bytes' => $result['size'],
            'renditions' => $result['renditions'],
            'files_verified_at' => now(),
        ]);
        // La version à télécharger, assemblée à part
        AssembleVideoDownload::dispatch($model->id)->afterCommit();

        return VideoResource::seenBy($model, 'seller', true);
    }

    /** Un brouillon de ce vendeur, qui peut encore l'envoyer. */
    private function editable(Request $request, string $video): Video
    {
        $model = $this->find($request, $video);
        $this->sellers->assertCanManage($model, $request->user(), $this->as);
        if ($model->status !== VideoStatus::Draft) {
            throw ValidationException::withMessages(['status' => 'Une vidéo mise en vente ne change plus de fichiers.']);
        }

        return $model;
    }

    private function reject(Video $video, string $reason): JsonResponse
    {
        $this->storage->deleteAll($video);
        $video->update(['files_status' => VideoFilesStatus::Rejected, 'rejection_reason' => $reason, 'size_bytes' => 0, 'renditions' => null]);

        return response()->json(['message' => $reason, 'errors' => ['files' => [$reason]]], 422);
    }
}
