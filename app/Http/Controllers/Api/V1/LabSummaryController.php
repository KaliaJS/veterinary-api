<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Http\Controllers\Controller;
use App\Models\Analyse;
use App\Models\Client;
use App\Models\LabSummary;
use App\Models\Patient;
use App\Services\Hospitalisation\OwnerMessages;
use App\Services\Labs\LabSummaries;
use App\Services\Labs\LabSummarySources;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Le message au propriétaire sur une analyse validée, par l'IA (09.11.2026) : le lire (avec,
 * avant le premier brouillon, les sources que l'analyse donnera), demander
 * un brouillon, corriger une partie, signer (droit de signer les comptes
 * rendus), envoyer la partie simple au propriétaire, dans sa messagerie.
 */
class LabSummaryController extends Controller
{
    private const array REFUSALS = [
        'signed' => 'Le message est signé : il ne se modifie plus.',
        'writing' => 'Le brouillon est en cours de rédaction.',
        'writings' => 'Le brouillon a déjà été rédigé plusieurs fois : écrivez le message vous-même.',
        'budget' => 'L’IA n’est plus disponible ce mois-ci : écrivez le message vous-même.',
    ];

    public function show(Analyse $analyse, LabSummarySources $sources): JsonResponse
    {
        $this->authorize('view', $analyse);
        $summary = LabSummary::query()->where('analyse_id', $analyse->id)->first();

        return response()->json(['data' => $this->present($analyse, $summary, $sources)]);
    }

    public function draft(Analyse $analyse, LabSummaries $summaries, LabSummarySources $sources): JsonResponse
    {
        $this->authorize('update', $analyse);
        if (! $analyse->estValidee()) {
            throw ValidationException::withMessages(['summary' => 'Le message se rédige une fois l’analyse validée.']);
        }
        $refusal = $summaries->request($summaries->of($analyse));
        if ($refusal !== null) {
            throw ValidationException::withMessages(['summary' => self::REFUSALS[$refusal]]);
        }

        return response()->json(['data' => $this->present($analyse, LabSummary::query()->where('analyse_id', $analyse->id)->first(), $sources)], 202);
    }

    public function update(Request $request, Analyse $analyse, LabSummaries $summaries, LabSummarySources $sources): JsonResponse
    {
        $this->authorize('update', $analyse);
        $data = $request->validate(['sections' => ['required', 'array'], 'sections.*' => ['nullable', 'string', 'max:5000']]);
        $unknown = array_diff(array_keys($data['sections']), LabSummary::SECTIONS);
        if ($unknown !== [] || ! $analyse->estValidee()) {
            throw ValidationException::withMessages(['sections' => $unknown !== [] ? 'Partie inconnue.' : 'Le message se rédige une fois l’analyse validée.']);
        }
        $summary = $summaries->of($analyse);
        DB::transaction(function () use ($summary, $data): void {
            $locked = LabSummary::query()->whereKey($summary->id)->lockForUpdate()->firstOrFail();
            if ($locked->isSigned() || $locked->status === 'writing') {
                throw ValidationException::withMessages(['summary' => self::REFUSALS[$locked->isSigned() ? 'signed' : 'writing']]);
            }
            $edited = $locked->edited_sections ?? [];
            foreach ($data['sections'] as $key => $text) {
                $draftText = implode("\n", array_column($locked->draft['sections'][$key] ?? [], 'text'));
                $edited = $locked->draft !== null && trim((string) $text) === $draftText ? array_values(array_diff($edited, [$key])) : array_values(array_unique([...$edited, $key]));
            }
            $locked->forceFill([
                'sections' => LabSummary::normalizeSections([...LabSummary::normalizeSections($locked->sections), ...$data['sections']]),
                'edited_sections' => $edited,
                'status' => in_array($locked->status, ['empty', 'failed'], true) ? 'draft' : $locked->status,
                'error' => null,
            ])->save();
        });
        LabSummary::announce($analyse->id, $analyse->clinique_id);

        return response()->json(['data' => $this->present($analyse, $summary->refresh(), $sources)]);
    }

    public function sign(Analyse $analyse, LabSummaries $summaries, LabSummarySources $sources): JsonResponse
    {
        $this->authorize('update', $analyse);
        Gate::authorize(DomainePermission::SignerComptesRendus->value);
        $summary = $summaries->of($analyse);
        DB::transaction(function () use ($summary): void {
            $locked = LabSummary::query()->whereKey($summary->id)->lockForUpdate()->firstOrFail();
            if ($locked->isSigned() || $locked->status === 'writing') {
                throw ValidationException::withMessages(['summary' => self::REFUSALS[$locked->isSigned() ? 'signed' : 'writing']]);
            }
            if (implode('', LabSummary::normalizeSections($locked->sections)) === '') {
                throw ValidationException::withMessages(['summary' => 'Le message est vide : rédigez-le ou écrivez-le d’abord.']);
            }
            $employe = app(ContexteClinique::class)->employe();
            $locked->forceFill(['status' => 'signed', 'signed_at' => now(), 'signed_by_id' => $employe?->id, 'signed_by_name' => $employe?->nom])->save();
        });
        LabSummary::announce($analyse->id, $analyse->clinique_id);

        return response()->json(['data' => $this->present($analyse, $summary->refresh(), $sources)]);
    }

    /** La partie simple part au propriétaire, dans sa messagerie (et par e-mail s'il ne l'a pas coupé) */
    public function send(Analyse $analyse, LabSummaries $summaries, LabSummarySources $sources, OwnerMessages $messages): JsonResponse
    {
        $this->authorize('update', $analyse);
        $client = $this->owner($analyse);
        if ($client?->user_id === null) {
            throw ValidationException::withMessages(['summary' => 'Ce propriétaire n’a pas de compte : il ne pourrait pas lire le message.']);
        }
        $summary = $summaries->of($analyse);
        [$message, $text, $first] = DB::transaction(function () use ($summary, $analyse, $client, $messages): array {
            $locked = LabSummary::query()->whereKey($summary->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isSigned()) {
                throw ValidationException::withMessages(['summary' => 'Le message part au propriétaire une fois relu et signé.']);
            }
            if ($locked->sent_at !== null) {
                throw ValidationException::withMessages(['summary' => 'Ce message a déjà été envoyé au propriétaire.']);
            }
            $owner = LabSummary::normalizeSections($locked->sections)['owner'];
            if ($owner === '') {
                throw ValidationException::withMessages(['summary' => 'La partie pour le propriétaire est vide.']);
            }
            $text = 'Résultats d’analyse de '.($analyse->patient->nom ?? 'votre animal').' du '.LabSummarySources::day(CarbonImmutable::parse($analyse->preleve_le), $analyse->clinique->fuseau_horaire ?? 'Europe/Zurich')."\n\n".$owner."\n\n".($locked->signed_by_name ? $locked->signed_by_name : '');
            [$message, $first] = $messages->createFor($analyse->clinique_id, $client, trim($text));
            $locked->forceFill(['sent_at' => now()])->save();

            return [$message, trim($text), $first];
        });
        $messages->notifyFor($analyse->clinique_id, $client, $message, $text, $first);
        LabSummary::announce($analyse->id, $analyse->clinique_id);

        return response()->json(['data' => $this->present($analyse, $summary->refresh(), $sources)]);
    }

    private function owner(Analyse $analyse): ?Client
    {
        $patient = Patient::query()->find($analyse->patient_id);

        return $patient ? Client::query()->find($patient->client_id) : null;
    }

    /** @return array<string, mixed> */
    private function present(Analyse $analyse, ?LabSummary $summary, LabSummarySources $sources): array
    {
        $sections = LabSummary::normalizeSections($summary?->sections);

        return [
            'analyse_id' => $analyse->id,
            'is_validated' => $analyse->estValidee(),
            'status' => $summary->status ?? 'empty',
            'error' => $summary?->error,
            'is_signed' => (bool) $summary?->isSigned(),
            'writings' => (int) ($summary->writings ?? 0),
            'writings_max' => (int) config('transcription.redactions_max'),
            'draft' => $summary?->draft,
            'preview_sources' => $summary?->draft === null && $analyse->estValidee() ? $sources->for($analyse) : null,
            'sections' => $sections,
            'edited_sections' => array_values($summary->edited_sections ?? []),
            'section_labels' => LabSummary::SECTION_LABELS,
            'signed_at' => $summary?->signed_at?->toIso8601String(),
            'signed_by_name' => $summary?->signed_by_name,
            'sent_at' => $summary?->sent_at?->toIso8601String(),
            'owner_has_account' => $this->owner($analyse)?->user_id !== null,
            'can_sign' => Gate::allows(DomainePermission::SignerComptesRendus->value),
        ];
    }
}
