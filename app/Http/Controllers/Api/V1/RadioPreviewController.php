<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ClicheDicomResource;
use App\Models\Patient;
use App\Support\Imagerie\RadiosDuDossier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * L'aperçu d'une radio (choix de Jim, 02.11.2026) : une image légère faite
 * par l'app à partir du DICOM d'origine (Cornerstone), à l'envoi ou à la
 * première ouverture de l'examen. Il s'enregistre une seule fois (le
 * premier gagne) : c'est une image dérivée, toute l'équipe qui voit le
 * dossier peut la poser. L'original n'est jamais touché.
 */
class RadioPreviewController extends Controller
{
    public const int MAX_KB = 1024;

    public function show(Patient $patient, int $examen, int $cliche): StreamedResponse
    {
        $this->authorize('view', $patient);
        $radio = RadiosDuDossier::cliche($patient, $examen, $cliche);
        abort_if($radio->preview_path === null || ! Storage::disk('prive')->exists($radio->preview_path), 404);

        return Storage::disk('prive')->response($radio->preview_path, null, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=604800']);
    }

    public function store(Request $request, Patient $patient, int $examen, int $cliche): ClicheDicomResource
    {
        $this->authorize('view', $patient);
        $request->validate([
            'image' => ['required', 'file', 'mimetypes:image/jpeg', 'max:'.self::MAX_KB, 'dimensions:max_width=1600,max_height=1600,min_width=16,min_height=16'],
        ], [
            'image.mimetypes' => 'L’aperçu doit être une image JPEG.',
            'image.max' => 'L’aperçu est trop lourd.',
            'image.dimensions' => 'L’aperçu n’a pas la bonne taille.',
        ]);
        $radio = RadiosDuDossier::cliche($patient, $examen, $cliche);
        if ($radio->taille_octets === null) {
            throw ValidationException::withMessages(['image' => 'Cette radio n’a pas de fichier d’origine.']);
        }

        $file = $request->file('image');
        $path = 'radios/apercus/'.$radio->id.'.jpg';

        // Le premier aperçu gagne : deux ouvertures en même temps n'en écrivent qu'un
        DB::transaction(function () use ($radio, $file, $path): void {
            $fresh = $radio->newQuery()->withoutGlobalScopes()->lockForUpdate()->findOrFail($radio->id);
            if ($fresh->preview_path !== null) {
                return;
            }
            Storage::disk('prive')->putFileAs('radios/apercus', $file, $radio->id.'.jpg');
            $fresh->forceFill(['preview_path' => $path, 'preview_bytes' => $file->getSize()])->save();
        });

        return new ClicheDicomResource($radio->fresh()->loadCount('annotations'));
    }
}
