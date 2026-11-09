<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Jobs\SplitRecordImport;
use App\Models\Patient;
use App\Models\RecordImport;
use App\Services\BudgetAnthropic;
use App\Services\CompteurMensuel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Importer un gros PDF reçu et le découper (étape 3, 09.11.2026). Le PDF
 * reste dans le navigateur : il envoie ici le texte de chaque page, et
 * l'image des seules pages scannées ; Claude Haiku 4.5 propose la découpe
 * (dans le budget de l'IA de la plateforme). Le vétérinaire la corrige et
 * la valide ; le navigateur range alors chaque pièce comme un document du
 * dossier. Sans IA (budget atteint, panne), la découpe se fait à la main.
 */
class RecordImportController extends Controller
{
    private const int MAX_PAGES = 300;

    /* Une découpe compte dans le budget par tranche de 50 pages (le coût suit la longueur) */
    private const int PAGES_PER_UNIT = 50;

    private const int MAX_IMAGES = 40;

    public function store(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('update', $patient);
        $data = $request->validate([
            'file_name' => ['nullable', 'string', 'max:255'],
            'pages' => ['required', 'array', 'min:1', 'max:'.self::MAX_PAGES],
            'pages.*.text' => ['present', 'nullable', 'string', 'max:20000'],
            'pages.*.image' => ['nullable', 'string', 'max:700000'],
        ], ['pages.max' => 'Ce PDF dépasse '.self::MAX_PAGES.' pages : découpez-le d’abord en plusieurs fichiers.']);
        $pages = collect($data['pages'])->values()->map(fn (array $p, int $i) => [
            'n' => $i + 1,
            'text' => (string) ($p['text'] ?? ''),
            'image' => isset($p['image']) && is_string($p['image']) && preg_match('/^[A-Za-z0-9+\/=]+$/', $p['image']) ? $p['image'] : null,
        ]);
        if ($pages->whereNotNull('image')->count() > self::MAX_IMAGES) {
            throw ValidationException::withMessages(['pages' => 'Trop de pages scannées pour la découpe par l’IA ('.self::MAX_IMAGES.' au plus) : découpez à la main.']);
        }

        $context = app(ContexteClinique::class);
        $import = RecordImport::query()->create([
            'organisation_id' => $patient->organisation_id,
            'patient_id' => $patient->id,
            'file_name' => isset($data['file_name']) ? mb_substr($data['file_name'], 0, 255) : null,
            'pages_count' => $pages->count(),
            'created_by_id' => $context->employe()?->id,
        ]);
        // Le budget atteint : la découpe se fait à la main, dans la même fenêtre
        if (app(BudgetAnthropic::class)->atteint()) {
            $import->forceFill(['status' => 'failed', 'error' => 'budget'])->save();

            return response()->json(['data' => self::present($import)], 201);
        }
        Storage::disk('prive')->put($import->pagesPath(), (string) json_encode($pages->all()));
        CompteurMensuel::ajouter('anthropic:decoupes', (int) ceil($pages->count() / self::PAGES_PER_UNIT));
        SplitRecordImport::dispatch($import->id);

        return response()->json(['data' => self::present($import->refresh())], 201);
    }

    public function show(RecordImport $import): JsonResponse
    {
        $this->authorize('view', Patient::query()->findOrFail($import->patient_id));

        return response()->json(['data' => self::present($import)]);
    }

    /** Les pièces rangées : la découpe est close */
    public function done(Request $request, RecordImport $import): JsonResponse
    {
        $this->authorize('update', Patient::query()->findOrFail($import->patient_id));
        $data = $request->validate(['documents_count' => ['required', 'integer', 'min:0', 'max:'.self::MAX_PAGES]]);
        // Close une seule fois (une découpe à la main sans attendre l'IA se clôt aussi)
        if ($import->status === 'done') {
            throw ValidationException::withMessages(['import' => 'Cette découpe est déjà rangée.']);
        }
        Storage::disk('prive')->delete($import->pagesPath());
        $import->forceFill(['status' => 'done', 'documents_count' => $data['documents_count']])->save();

        return response()->json(['data' => self::present($import)]);
    }

    /** @return array<string, mixed> */
    public static function present(RecordImport $import): array
    {
        return [
            'id' => $import->id,
            'patient_id' => $import->patient_id,
            'status' => $import->status,
            'error' => $import->error,
            'pages_count' => $import->pages_count,
            'proposal' => $import->status === 'ready' || $import->status === 'done' ? ($import->proposal ?? []) : null,
            'kinds' => RecordImport::KINDS,
        ];
    }
}
