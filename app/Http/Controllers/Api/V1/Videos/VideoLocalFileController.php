<?php

namespace App\Http\Controllers\Api\V1\Videos;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Services\Videos\VideoStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sur le disque local seulement (développement, tests) : ce que font les
 * adresses signées du bucket en production. Lien signé obligatoire ;
 * écrire demande en plus le drapeau `upload` signé avec lui. En
 * production (bucket), ces routes ne servent jamais rien.
 */
class VideoLocalFileController extends Controller
{
    /** La taille d'un fichier envoyé (un segment de quelques secondes). */
    private const MAX_BYTES = 64 * 1024 * 1024;

    public function __construct(private VideoStorage $storage) {}

    public function show(Request $request, string $video, string $name): StreamedResponse
    {
        $model = $this->video($video, $name);
        $path = $model->folder().'/'.$name;
        abort_unless($this->storage->disk()->exists($path), 404);
        $as = $request->query('as');

        return $this->storage->disk()->response($path, is_string($as) ? $as : null, ['Content-Type' => VideoStorage::contentType($name)], is_string($as) ? 'attachment' : 'inline');
    }

    public function store(Request $request, string $video, string $name): Response
    {
        abort_unless($request->boolean('upload'), 403);
        abort_unless(preg_match(VideoStorage::UPLOADABLE, $name) === 1, 404);
        $model = $this->video($video, $name);
        $body = $request->getContent();
        abort_if(strlen($body) > self::MAX_BYTES, 413);
        $this->storage->disk()->put($model->folder().'/'.$name, $body);

        return response()->noContent();
    }

    private function video(string $uuid, string $name): Video
    {
        abort_if(($this->storage->disk()->getConfig()['driver'] ?? null) === 's3', 404);
        abort_unless(VideoStorage::isValidName($name), 404);

        return Video::query()->where('uuid', $uuid)->firstOrFail();
    }
}
