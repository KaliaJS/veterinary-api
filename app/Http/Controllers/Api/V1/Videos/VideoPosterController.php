<?php

namespace App\Http\Controllers\Api\V1\Videos;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Services\Videos\VideoPosters;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * L'affiche d'une vidéo de formation (31.10.2026), comme la photo d'une
 * clinique : une route publique en cache (un jour), qui ne sert que les
 * versions WebP d'une affiche encore rattachée à une vidéo.
 */
class VideoPosterController extends Controller
{
    public function show(string $token, int $width): StreamedResponse
    {
        abort_unless(Video::query()->where('poster_token', $token)->exists(), 404);
        $path = VideoPosters::path($token, $width);
        abort_unless(Storage::disk('prive')->exists($path), 404);

        return Storage::disk('prive')->response($path, null, [
            'Content-Type' => 'image/webp',
            // Un jour, pas un an (vérification du 31.10.2026) : une affiche remplacée ou retirée ne reste pas servie par les caches
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
