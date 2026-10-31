<?php

namespace App\Http\Controllers\Api\V1\Videos;

use App\Enums\VideoFilesStatus;
use App\Enums\VideoStatus;
use App\Http\Requests\V1\Videos\StartUploadRequest;
use App\Http\Requests\V1\Videos\UploadUrlsRequest;
use App\Http\Resources\V1\VideoResource;
use App\Jobs\AssembleVideoDownload;
use App\Jobs\RecheckVideoFiles;
use App\Models\Video;
use App\Services\Videos\HlsPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * L'envoi des fichiers d'une vidéo (31.10.2026), directement du navigateur
 * au bucket : le serveur signe, fichier par fichier, une adresse qui ne
 * permet que de déposer ce fichier, pendant 15 minutes. Puis il contrôle
 * le paquet reçu avant toute mise en vente.
 */
abstract class SellerVideoUploadController extends SellerVideoController
{
    /** Durée de validité d'une adresse d'envoi. */
    public const URL_MINUTES = 15;

    /** Marge au-delà de la place annoncée (listes, arrondis de l'estimation) */
    private const DEPOSIT_MARGIN = 50_000_000;

    /** Avant d'envoyer : la durée et la taille estimée, refusées avant de commencer si elles dépassent. */
    public function start(StartUploadRequest $request, string $video): JsonResponse
    {
        $model = $this->editable($request, $video);
        $storage = $this->sellers->storage($model);
        // Les autres envois en cours comptent pour la place qu'ils ont annoncée
        $after = $storage['used'] - $model->storageBytes() + (int) $request->validated('estimated_bytes');
        if ($after > $storage['limit']) {
            throw ValidationException::withMessages(['estimated_bytes' => 'Votre stockage est plein : cette vidéo ne tient pas dans ce qui reste.']);
        }

        // D'abord la vidéo passe en envoi, seulement si elle est encore en brouillon : une mise en vente
        // au même instant (autre onglet) l'emporte, ou bien échoue faute de fichiers prêts ; jamais en vente sans fichiers
        $started = Video::query()->whereKey($model->id)->where('status', VideoStatus::Draft->value)->update([
            'files_status' => VideoFilesStatus::Pending->value, 'rejection_reason' => null, 'size_bytes' => 0,
            'upload_started_at' => now(), 'upload_expected_bytes' => (int) $request->validated('estimated_bytes'),
            'duration_seconds' => null, 'width' => null, 'height' => null, 'renditions' => null,
            'download_path' => null, 'files_verified_at' => null, 'files_checked_at' => null,
            // Une demande de mise en vente valait pour les fichiers d'avant
            'publish_requested_at' => null, 'publish_requested_by' => null,
            'updated_at' => now(),
        ]);
        if ($started === 0) {
            throw ValidationException::withMessages(['status' => 'La vidéo vient d’être mise en vente : ses fichiers ne changent plus.']);
        }
        $model->refresh();
        // Puis les fichiers d'avant s'effacent ; ce qu'on en avait regardé ne vaut pas pour les nouveaux
        $this->storage->deleteAll($model);
        $model->views()->update(['watched_ranges' => null, 'watched_seconds' => 0, 'completed_at' => null]);

        return response()->json(['data' => ['url_minutes' => self::URL_MINUTES]]);
    }

    /** Les adresses d'envoi des fichiers demandés. */
    public function urls(UploadUrlsRequest $request, string $video): JsonResponse
    {
        $model = $this->editable($request, $video);
        // Une fois la vidéo contrôlée, plus aucune adresse d'envoi : un nouvel envoi recommence par start
        if ($model->files_status !== VideoFilesStatus::Pending) {
            throw ValidationException::withMessages(['files' => 'La vidéo est déjà envoyée et contrôlée : pour la changer, recommencez l’envoi.']);
        }
        // Pas d'adresse sans envoi annoncé : sa durée et sa place ont été contrôlées au début
        if ($model->upload_started_at === null) {
            throw ValidationException::withMessages(['files' => 'Commencez l’envoi depuis Veterinary : la place se contrôle avant.']);
        }
        // Ce qui est déjà déposé ne dépasse ni la place annoncée (avec une marge) ni le stockage
        $deposited = array_sum($this->listed($model));
        $storage = $this->sellers->storage($model);
        $announced = (int) ($model->upload_expected_bytes ?? 0);
        if ($deposited > $announced * 1.5 + self::DEPOSIT_MARGIN || $storage['limit'] < $storage['used'] - $model->storageBytes() + $deposited) {
            throw ValidationException::withMessages(['files' => 'La vidéo dépasse la place annoncée ou votre stockage : recommencez l’envoi.']);
        }
        $expires = now()->addMinutes(self::URL_MINUTES);
        // Un long envoi reste en cours : la purge des envois abandonnés ne le prend pas
        $model->touch();

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

        return response()->json(['data' => (object) $this->listed($model)]);
    }

    /** Le dossier de la vidéo ; stockage injoignable : 503, ce n'est pas un refus, on réessaiera. */
    private function listed(Video $model): array
    {
        try {
            return $this->storage->files($model);
        } catch (Throwable $e) {
            report($e);
            abort(503, 'Le stockage des vidéos ne répond pas : réessayez dans un instant.');
        }
    }

    /** Le paquet reçu, contrôlé : prêt à vendre, ou refusé (et effacé) avec sa raison. */
    public function finish(Request $request, string $video): VideoResource|JsonResponse
    {
        $model = $this->editable($request, $video);
        // Déjà contrôlée (la réponse s'est perdue, l'écran redemande le contrôle) : on rend ce qui est,
        // sans rien relire ni effacer (la version à télécharger y est peut-être déjà)
        if ($model->files_status === VideoFilesStatus::Ready) {
            return VideoResource::seenBy($model, 'seller', true);
        }
        $result = app(HlsPackage::class)->inspect($model);
        // Stockage injoignable : rien n'est jugé ni effacé ; 503, pas 422 : ce n'est pas un refus, on réessaiera
        if ($result['ok'] === false && ! empty($result['transient'])) {
            return response()->json(['message' => $result['reason']], 503);
        }
        if ($result['ok'] === false) {
            return $this->reject($model, $result['reason']);
        }

        // La place réelle, avec la version à télécharger qui s'y ajoutera
        $storage = $this->sellers->storage($model);
        if ($storage['limit'] < $storage['used'] - $model->storageBytes() + $result['size'] + $result['download_bytes']) {
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
            'files_checked_at' => null,
        ]);
        // La version à télécharger, assemblée à part
        AssembleVideoDownload::dispatch($model->id, $model->uploadStamp())->afterCommit();
        // Chaque morceau relu tout de suite (la mise en vente l'attend), puis encore une fois
        // quand les dernières adresses d'envoi ont expiré (15 minutes)
        RecheckVideoFiles::dispatch($model->id, 'now', $model->uploadStamp())->afterCommit();
        RecheckVideoFiles::dispatch($model->id, 'late', $model->uploadStamp())->delay(now()->addMinutes(self::URL_MINUTES + 1))->afterCommit();

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
        // Achetée : ses fichiers ne s'effacent ni ne se remplacent, ceux qui l'ont payée la regardent encore
        if ($model->hasBuyers()) {
            throw ValidationException::withMessages(['status' => 'Cette vidéo a été achetée : ses fichiers ne changent plus.']);
        }

        return $model;
    }

    private function reject(Video $video, string $reason): JsonResponse
    {
        $this->storage->deleteAll($video);
        $video->update(['files_status' => VideoFilesStatus::Rejected, 'rejection_reason' => $reason, 'size_bytes' => 0, 'renditions' => null, 'publish_requested_at' => null, 'publish_requested_by' => null]);

        return response()->json(['message' => $reason, 'errors' => ['files' => [$reason]]], 422);
    }
}
