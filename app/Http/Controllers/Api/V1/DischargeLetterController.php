<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Enums\StatutEnregistrement;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\DischargeLetterResource;
use App\Models\DischargeLetter;
use App\Models\EnregistrementConsultation;
use App\Models\Sejour;
use App\Services\Hospitalisation\DischargeLetters;
use App\Services\Hospitalisation\OwnerMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * La lettre de sortie d'un séjour (choix de Jim, 09.11.2026) : la lire,
 * taper les consignes pour la maison, demander un brouillon à l'IA,
 * corriger les rubriques, la signer (droit de signer les comptes rendus :
 * elle est alors figée, avec les médicaments, le contrôle et la clinique
 * recopiés), puis l'envoyer au propriétaire. Rien n'est à la lettre tant
 * qu'elle n'est pas signée.
 */
class DischargeLetterController extends Controller
{
    private const REFUSALS = [
        'signed' => 'Cette lettre est signée : elle ne change plus.',
        'writing' => 'Le brouillon est en cours de rédaction.',
        'writings' => 'Le brouillon a déjà été rédigé plusieurs fois : écrivez la lettre vous-même.',
        'budget' => 'L’IA n’est plus disponible ce mois-ci : écrivez la lettre vous-même.',
    ];

    public function show(Sejour $sejour): DischargeLetterResource
    {
        $this->authorize('view', $sejour);

        return $this->resource($sejour, $this->letter($sejour));
    }

    public function draft(Sejour $sejour, DischargeLetters $letters): JsonResponse
    {
        $this->authorize('update', $sejour);

        $refusal = $letters->request($sejour);
        if ($refusal !== null) {
            throw ValidationException::withMessages(['letter' => self::REFUSALS[$refusal]]);
        }

        return $this->resource($sejour, $this->letter($sejour))->response()->setStatusCode(202);
    }

    /** Les consignes pour la maison, et les rubriques relues */
    public function update(Request $request, Sejour $sejour): DischargeLetterResource
    {
        $this->authorize('update', $sejour);
        $data = $request->validate([
            'home_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'sections' => ['sometimes', 'array'],
            'sections.*' => ['nullable', 'string', 'max:10000'],
        ]);
        $unknown = array_diff(array_keys($data['sections'] ?? []), DischargeLetter::SECTIONS);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['sections' => 'Rubrique inconnue : '.implode(', ', $unknown).'.']);
        }

        $letter = DB::transaction(function () use ($sejour, $data): DischargeLetter {
            DischargeLetters::ensure($sejour);
            $letter = DischargeLetter::query()->where('sejour_id', $sejour->id)->lockForUpdate()->firstOrFail();
            if ($letter->isSigned()) {
                throw ValidationException::withMessages(['letter' => self::REFUSALS['signed']]);
            }
            if (array_key_exists('home_notes', $data)) {
                $notes = trim((string) $data['home_notes']) ?: null;
                // Changées pendant une rédaction (qui a déjà lu ses sources) : un nouveau brouillon suit
                if ($letter->status === 'writing' && $notes !== $letter->home_notes) {
                    $letter->is_redraft_pending = true;
                }
                $letter->home_notes = $notes;
            }
            if (isset($data['sections'])) {
                if ($letter->status === 'writing') {
                    throw ValidationException::withMessages(['letter' => self::REFUSALS['writing']]);
                }
                // Corrigées : un nouveau brouillon ne les réécrit plus ; une rubrique
                // remise au texte exact du brouillon redevient non touchée
                $edited = $letter->edited_sections ?? [];
                $editedAt = $letter->edited_at ?? [];
                foreach ($data['sections'] as $key => $text) {
                    $lines = $letter->draft['sections'][$key] ?? null;
                    $draftText = implode("\n", array_column(is_array($lines) ? $lines : [], 'text'));
                    if ($letter->draft !== null && trim((string) $text) === $draftText) {
                        $edited = array_values(array_diff($edited, [$key]));
                        unset($editedAt[$key]);
                    } else {
                        $edited = array_values(array_unique([...$edited, $key]));
                        $editedAt[$key] = now()->toIso8601String();
                    }
                }
                $letter->forceFill([
                    'sections' => DischargeLetter::normalizeSections([...DischargeLetter::normalizeSections($letter->sections), ...$data['sections']]),
                    'edited_sections' => $edited,
                    'edited_at' => $editedAt,
                    // Écrite à la main après une rédaction échouée : un brouillon à relire, sans l'échec
                    'status' => in_array($letter->status, ['empty', 'failed'], true) ? 'draft' : $letter->status,
                    'error' => $letter->status === 'failed' ? null : $letter->error,
                ]);
            }
            $letter->save();

            return $letter;
        });
        Sejour::announce($sejour->id, $sejour->clinique_id);

        return $this->resource($sejour, $letter);
    }

    public function sign(Request $request, Sejour $sejour, DischargeLetters $letters): DischargeLetterResource
    {
        $this->authorize('update', $sejour);
        Gate::authorize(DomainePermission::SignerComptesRendus->value);

        $letter = DB::transaction(function () use ($sejour, $request, $letters): DischargeLetter {
            // Sous le verrou du séjour, comme une dictée qui commence : jamais une dictée ouverte sur une lettre signée
            $locked = Sejour::withoutGlobalScopes()->whereKey($sejour->id)->lockForUpdate()->firstOrFail();
            // Choix de Jim (09.11.2026) : signée une fois l'animal sorti, avec les médicaments et le contrôle de la sortie
            if (! $locked->estSorti()) {
                throw ValidationException::withMessages(['letter' => 'La lettre se signe une fois l’animal sorti : les médicaments et le contrôle sont alors ceux de la sortie.']);
            }
            $dictating = EnregistrementConsultation::withoutGlobalScopes()->where('sejour_id', $sejour->id)
                ->whereIn('statut', [StatutEnregistrement::Enregistrement->value, StatutEnregistrement::Transcription->value, StatutEnregistrement::Redaction->value])
                ->exists();
            if ($dictating) {
                throw ValidationException::withMessages(['letter' => 'Une dictée est en cours : terminez-la, ou attendez sa transcription, avant de signer.']);
            }
            $letter = DischargeLetter::query()->where('sejour_id', $sejour->id)->lockForUpdate()->first();
            if ($letter?->isSigned()) {
                throw ValidationException::withMessages(['letter' => self::REFUSALS['signed']]);
            }
            if ($letter?->status === 'writing') {
                throw ValidationException::withMessages(['letter' => self::REFUSALS['writing']]);
            }
            if (! $letter || implode('', DischargeLetter::normalizeSections($letter->sections)) === '') {
                throw ValidationException::withMessages(['letter' => 'La lettre est vide.']);
            }
            $employe = app(ContexteClinique::class)->employe();
            $letter->forceFill([
                'status' => 'signed',
                'error' => null,
                // Ce qui est recopié est figé avec la lettre
                'copied' => $letters->copied($locked),
                'signed_at' => now(),
                'signed_by_id' => $employe?->id,
                'signed_by_name' => $employe->nom ?? $request->user()?->name,
            ])->save();
            // La dictée est rattachée à la lettre signée
            $closing = EnregistrementConsultation::withoutGlobalScopes()->where('sejour_id', $sejour->id)
                ->whereIn('statut', [StatutEnregistrement::Pret->value, StatutEnregistrement::Erreur->value]);
            $recordings = $closing->pluck('id')->all();
            $closing->update(['statut' => StatutEnregistrement::Valide->value, 'valide_le' => now()]);
            foreach ($recordings as $recording) {
                EnregistrementConsultation::annoncer($recording);
            }

            return $letter;
        });
        Sejour::announce($sejour->id, $sejour->clinique_id);

        return $this->resource($sejour, $letter);
    }

    /** La lettre signée part au propriétaire, dans sa messagerie (et par e-mail s'il ne l'a pas coupé) */
    public function send(Sejour $sejour, DischargeLetters $letters, OwnerMessages $messages): DischargeLetterResource
    {
        $this->authorize('update', $sejour);
        $client = $messages->owner($sejour);
        if ($client?->user_id === null) {
            throw ValidationException::withMessages(['letter' => 'Ce propriétaire n’a pas de compte : il ne pourrait pas lire la lettre. Imprimez-la.']);
        }

        // Sous verrou : deux clics, deux postes, un seul envoi
        [$letter, $message, $text, $first] = DB::transaction(function () use ($sejour, $client, $letters, $messages): array {
            $letter = DischargeLetter::query()->where('sejour_id', $sejour->id)->lockForUpdate()->first();
            if (! $letter?->isSigned()) {
                throw ValidationException::withMessages(['letter' => 'La lettre part au propriétaire une fois relue et signée.']);
            }
            if ($letter->sent_at !== null) {
                throw ValidationException::withMessages(['letter' => 'Cette lettre a déjà été envoyée au propriétaire.']);
            }
            $text = $letters->plainText($sejour, $letter);
            [$message, $first] = $messages->create($sejour, $client, $text);
            $letter->forceFill(['sent_at' => now()])->save();

            return [$letter, $message, $text, $first];
        });
        $messages->notify($sejour, $client, $message, $text, $first);
        Sejour::announce($sejour->id, $sejour->clinique_id);

        return $this->resource($sejour, $letter);
    }

    private function letter(Sejour $sejour): DischargeLetter
    {
        return DischargeLetter::query()->where('sejour_id', $sejour->id)->first() ?? new DischargeLetter(['sejour_id' => $sejour->id]);
    }

    private function resource(Sejour $sejour, DischargeLetter $letter): DischargeLetterResource
    {
        return new DischargeLetterResource($letter->setRelation('sejour', $sejour));
    }
}
