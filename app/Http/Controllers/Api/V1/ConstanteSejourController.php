<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Hospitalisation\EnregistrerConstanteRequest;
use App\Http\Resources\V1\SejourConstanteResource;
use App\Models\Sejour;
use App\Services\Hospitalisation\StaySheets;
use Illuminate\Validation\ValidationException;

/**
 * Relevés de constantes d'un séjour (T°, FC, FR) — enfants du dossier,
 * résolus via le séjour. Ils gardent qui les a relevés (03.11.2026) ; un
 * relevé oublié se note après coup sur sa feuille (`sheet_day`).
 */
class ConstanteSejourController extends Controller
{
    public function __construct(private StaySheets $sheets) {}

    public function store(EnregistrerConstanteRequest $request, Sejour $sejour): SejourConstanteResource
    {
        $this->authorize('update', $sejour);

        $data = $request->validated();
        // L'heure du relevé ; sur une feuille close, une note tardive (03.11.2026)
        $when = $this->sheets->gestureTime($sejour, $data['sheet_day'] ?? null, isset($data['sheet_day']) ? (int) $data['mesure_a'] : null, key: 'mesure_a');
        $isToday = ! isset($data['sheet_day']);
        // Maintenant : le relevé n'a pas d'heure à venir (deuxième vérification, 03.11.2026)
        if ($isToday && $this->sheets->instant($sejour, aujourdhui($sejour->clinique)->toDateString(), (int) $data['mesure_a'])->gt(now()->addMinute())) {
            throw ValidationException::withMessages(['mesure_a' => 'Cette heure est à venir : le relevé se note quand il est fait.']);
        }
        $constante = $sejour->constantes()->create([
            'jour' => $isToday ? aujourdhui($sejour->clinique)->toDateString() : $when['day'],
            'mesure_a' => $data['mesure_a'],
            'temperature' => $data['temperature'],
            'fc' => $data['fc'],
            'fr' => $data['fr'],
            'by_id' => $request->user()?->id,
            'by_name' => $this->sheets->authorName($request->user()),
            'late_noted_at' => $when['late'] ? now() : null,
        ]);
        Sejour::announce($sejour->id, $sejour->clinique_id);

        return new SejourConstanteResource($constante);
    }
}
