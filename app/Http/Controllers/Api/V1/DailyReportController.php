<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Sejour;
use App\Models\StaySheet;
use App\Services\Hospitalisation\DailyReports;
use App\Services\Hospitalisation\StaySheets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La feuille d'un jour et son rapport rédigé par l'IA (03.11.2026) : le
 * demander (il part en file), le corriger tant qu'il est à relire, le
 * signer, l'envoyer au propriétaire une fois signé.
 */
class DailyReportController extends Controller
{
    public function __construct(private DailyReports $reports, private StaySheets $sheets) {}

    public function show(Sejour $sejour, string $jour): JsonResponse
    {
        $this->authorize('view', $sejour);
        $sheet = $jour > $this->sheets->lastDay($sejour) && $jour >= $this->sheets->admissionDay($sejour)
            ? null
            : $this->reports->sheet($sejour, $jour);

        return $this->respond($sejour, $sheet, $jour);
    }

    public function draft(Sejour $sejour, string $jour): JsonResponse
    {
        $this->authorize('update', $sejour);
        $sheet = $this->reports->sheet($sejour, $jour);
        $this->reports->request($sejour, $sheet);

        return $this->respond($sejour, $sheet->refresh(), $jour);
    }

    public function update(Request $request, Sejour $sejour, string $jour): JsonResponse
    {
        $this->authorize('update', $sejour);
        $text = $request->validate(['text' => ['required', 'string', 'max:5000']])['text'];
        $sheet = $this->reports->sheet($sejour, $jour);
        $this->reports->edit($sejour, $sheet, $text);

        return $this->respond($sejour, $sheet->refresh(), $jour);
    }

    /** Le texte qu'on signe accompagne la signature : l'API refuse un autre texte que le dernier enregistré */
    public function sign(Request $request, Sejour $sejour, string $jour): JsonResponse
    {
        $this->authorize('update', $sejour);
        $text = $request->validate(['text' => ['required', 'string', 'max:5000']], ['text.required' => 'Le texte signé accompagne la signature.'])['text'];
        $sheet = $this->reports->sheet($sejour, $jour);
        $this->reports->sign($sejour, $sheet, $request->user(), $text);

        return $this->respond($sejour, $sheet->refresh(), $jour);
    }

    public function send(Request $request, Sejour $sejour, string $jour): JsonResponse
    {
        $this->authorize('update', $sejour);
        $sheet = $this->reports->sheet($sejour, $jour);
        $this->reports->send($sejour->load('patient'), $sheet, $request->user());

        return $this->respond($sejour, $sheet->refresh(), $jour);
    }

    private function respond(Sejour $sejour, ?StaySheet $sheet, string $jour): JsonResponse
    {
        return response()->json(['data' => [
            'day' => $jour,
            'state' => $this->sheets->state($sejour, $jour),
            'report' => $this->reports->toArray($sejour->loadMissing('patient.client'), $sheet, $jour),
        ]]);
    }
}
