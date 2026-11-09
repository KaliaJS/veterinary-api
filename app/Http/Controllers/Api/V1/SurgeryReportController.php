<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Enums\StatutEnregistrement;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\SurgeryReportResource;
use App\Models\EnregistrementConsultation;
use App\Models\Intervention;
use App\Models\SurgeryReport;
use App\Services\Bloc\SurgeryReportDrafts;
use App\Services\Bloc\SurgeryReportSources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Le rapport de bloc d'une intervention (C, choix de Jim, 09.11.2026) :
 * le lire, demander un brouillon à l'IA (écran, notes, dictée), corriger
 * les rubriques, le valider (droit de signer les comptes rendus, comme les
 * comptes rendus : il est alors figé), puis y ajouter des compléments
 * datés et signés. Rien n'est au rapport tant qu'il n'est pas validé.
 */
class SurgeryReportController extends Controller
{
    private const REFUSALS = [
        'validated' => 'Ce rapport est validé : ajoutez un complément.',
        'writing' => 'Le brouillon est en cours de rédaction.',
        'writings' => 'Le brouillon a déjà été rédigé plusieurs fois : écrivez le rapport vous-même.',
        'budget' => 'L’IA n’est plus disponible ce mois-ci : écrivez le rapport vous-même.',
    ];

    public function show(Intervention $intervention): SurgeryReportResource
    {
        $this->authorize('view', $intervention);

        return new SurgeryReportResource($this->report($intervention));
    }

    public function draft(Intervention $intervention, SurgeryReportDrafts $drafts): JsonResponse
    {
        $this->authorize('update', $intervention);
        $this->ensureReportable($intervention);

        $refusal = $drafts->request($intervention);
        if ($refusal !== null) {
            throw ValidationException::withMessages(['report' => self::REFUSALS[$refusal]]);
        }

        return (new SurgeryReportResource($this->report($intervention)))->response()->setStatusCode(202);
    }

    public function update(Request $request, Intervention $intervention): SurgeryReportResource
    {
        $this->authorize('update', $intervention);
        $data = $request->validate([
            'sections' => ['required', 'array'],
            'sections.*' => ['nullable', 'string', 'max:10000'],
        ]);
        $this->ensureReportable($intervention);
        $unknown = array_diff(array_keys($data['sections']), SurgeryReport::SECTIONS);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['sections' => 'Rubrique inconnue : '.implode(', ', $unknown).'.']);
        }

        $report = DB::transaction(function () use ($intervention, $data): SurgeryReport {
            SurgeryReportDrafts::ensure($intervention);
            $report = SurgeryReport::query()->where('intervention_id', $intervention->id)->lockForUpdate()->firstOrFail();
            if ($report->isValidated()) {
                throw ValidationException::withMessages(['report' => self::REFUSALS['validated']]);
            }
            if ($report->status === 'writing') {
                throw ValidationException::withMessages(['report' => self::REFUSALS['writing']]);
            }
            $report->forceFill([
                'sections' => SurgeryReport::normalizeSections([...SurgeryReport::normalizeSections($report->sections), ...$data['sections']]),
                // Corrigées : un nouveau brouillon ne les réécrit plus
                'edited_sections' => array_values(array_unique([...($report->edited_sections ?? []), ...array_keys($data['sections'])])),
                'status' => $report->status === 'empty' ? 'draft' : $report->status,
            ])->save();

            return $report;
        });

        return new SurgeryReportResource($report->load('complements'));
    }

    public function validateReport(Request $request, Intervention $intervention): SurgeryReportResource
    {
        $this->authorize('update', $intervention);
        Gate::authorize(DomainePermission::SignerComptesRendus->value);

        $this->ensureReportable($intervention);
        $dictating = EnregistrementConsultation::withoutGlobalScopes()->where('intervention_id', $intervention->id)
            ->whereIn('statut', [StatutEnregistrement::Enregistrement->value, StatutEnregistrement::Transcription->value, StatutEnregistrement::Redaction->value])
            ->exists();
        if ($dictating) {
            throw ValidationException::withMessages(['report' => 'Une dictée est en cours : terminez-la, ou attendez sa transcription, avant de valider.']);
        }

        $report = DB::transaction(function () use ($intervention, $request): SurgeryReport {
            $report = SurgeryReport::query()->where('intervention_id', $intervention->id)->lockForUpdate()->first();
            if ($report?->isValidated()) {
                throw ValidationException::withMessages(['report' => self::REFUSALS['validated']]);
            }
            if ($report?->status === 'writing') {
                throw ValidationException::withMessages(['report' => self::REFUSALS['writing']]);
            }
            if (! $report || implode('', SurgeryReport::normalizeSections($report->sections)) === '') {
                throw ValidationException::withMessages(['report' => 'Le rapport est vide.']);
            }
            $employe = app(ContexteClinique::class)->employe();
            $report->forceFill([
                'status' => 'validated',
                'error' => null,
                'validated_at' => now(),
                'validated_by_id' => $employe?->id,
                'validated_by_name' => $employe->nom ?? $request->user()?->name,
            ])->save();
            // La dictée est rattachée au rapport validé : une nouvelle repart d'une fiche neuve
            $closing = EnregistrementConsultation::withoutGlobalScopes()->where('intervention_id', $intervention->id)
                ->whereIn('statut', [StatutEnregistrement::Pret->value, StatutEnregistrement::Erreur->value]);
            $recordings = $closing->pluck('id')->all();
            $closing->update(['statut' => StatutEnregistrement::Valide->value, 'valide_le' => now()]);
            // Les autres postes ferment leur bulle de dictée (2e vérification)
            foreach ($recordings as $recording) {
                EnregistrementConsultation::annoncer($recording);
            }

            return $report;
        });
        SurgeryReport::announce($intervention);

        return new SurgeryReportResource($report->load('complements'));
    }

    public function complement(Request $request, Intervention $intervention): JsonResponse
    {
        $this->authorize('update', $intervention);
        Gate::authorize(DomainePermission::SignerComptesRendus->value);
        $data = $request->validate(['text' => ['required', 'string', 'max:5000']]);

        $report = $this->report($intervention);
        if (! $report->isValidated()) {
            throw ValidationException::withMessages(['report' => 'Un complément s’ajoute à un rapport validé.']);
        }
        $employe = app(ContexteClinique::class)->employe();
        $report->complements()->create([
            'text' => trim($data['text']),
            'by_id' => $employe?->id,
            'by_name' => $employe->nom ?? $request->user()?->name,
        ]);
        SurgeryReport::announce($intervention);

        return (new SurgeryReportResource($report->load('complements')))->response()->setStatusCode(201);
    }

    /** Le rapport se rédige, se corrige et se valide une fois l'intervention au réveil ou finie */
    private function ensureReportable(Intervention $intervention): void
    {
        if (! SurgeryReportSources::isReportable($intervention)) {
            throw ValidationException::withMessages(['report' => 'Le rapport se rédige une fois l’intervention au réveil ou finie.']);
        }
    }

    private function report(Intervention $intervention): SurgeryReport
    {
        return ($intervention->report ?? new SurgeryReport(['intervention_id' => $intervention->id]))->load('complements');
    }
}
