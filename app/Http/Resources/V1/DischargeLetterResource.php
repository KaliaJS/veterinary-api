<?php

namespace App\Http\Resources\V1;

use App\Enums\DomainePermission;
use App\Models\DischargeLetter;
use App\Models\EnregistrementConsultation;
use App\Services\Hospitalisation\DischargeLetters;
use App\Services\Hospitalisation\DischargeLetterSources;
use App\Services\Hospitalisation\OwnerMessages;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * La lettre de sortie (09.11.2026) : son état, les consignes tapées, le
 * brouillon de l'IA (sources numérotées, phrases et leurs sources, doutes),
 * le texte relu par rubrique, ce qui est recopié (médicaments, contrôle,
 * clinique : en direct avant la signature, figé après), la lettre telle
 * que le propriétaire la lit, la signature et l'envoi. Toutes les rubriques
 * sont toujours là, vides comprises (à compléter).
 *
 * @mixin DischargeLetter
 */
class DischargeLetterResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $letters = app(DischargeLetters::class);
        $sejour = $this->sejour;
        $owner = app(OwnerMessages::class)->owner($sejour);
        $hasAccount = $owner?->user_id !== null;

        return [
            'sejour_id' => $this->sejour_id,
            // L'en-tête de la fenêtre et de la lettre imprimée
            'stay' => [
                'patient_name' => $sejour->patient?->nom,
                'owner_name' => $owner?->fullName(),
                'box' => $sejour->box,
                'motif' => $sejour->motif,
                'admitted_at' => $sejour->admis_le->toIso8601String(),
                'discharged_at' => $sejour->sorti_le?->toIso8601String(),
            ],
            'status' => $this->status,
            'error' => $this->error,
            'is_signed' => $this->isSigned(),
            'writings' => (int) $this->writings,
            'writings_max' => (int) config('transcription.redactions_max'),
            'home_notes' => (string) $this->home_notes,
            'draft' => $this->draft,
            // Avant le premier brouillon, les sources que le dossier donnera (la fenêtre les montre)
            'preview_sources' => $this->draft === null && ! $this->isSigned() ? app(DischargeLetterSources::class)->for($sejour) : null,
            'sections' => DischargeLetter::normalizeSections($this->sections),
            'edited_sections' => array_values($this->edited_sections ?? []),
            'edited_at' => (object) ($this->edited_at ?? []),
            'section_labels' => [...DischargeLetter::SECTION_LABELS, ...DischargeLetters::COPIED_LABELS],
            'order' => DischargeLetters::ORDER,
            'copied' => $this->isSigned() && is_array($this->copied) ? $this->copied : $letters->copied($sejour),
            'blocks' => $letters->blocks($sejour, $this->resource),
            'written_at' => $this->written_at?->toIso8601String(),
            'signed_at' => $this->signed_at?->toIso8601String(),
            'signed_by_name' => $this->signed_by_name,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'can_sign' => Gate::allows(DomainePermission::SignerComptesRendus->value),
            // Signée une fois l'animal sorti (choix de Jim, 09.11.2026)
            'is_signable' => $sejour->estSorti(),
            'owner_has_account' => $hasAccount,
            'can_send' => $this->isSigned() && $this->sent_at === null && $hasAccount,
            // Les dictées transcrites : la fenêtre montre celles que le brouillon n'a pas encore prises
            'dictations' => DischargeLetterSources::recordings((int) $this->sejour_id)->map(fn (EnregistrementConsultation $r) => [
                'id' => $r->id,
                'redige_le' => $r->redige_le?->toIso8601String(),
                'segments' => self::segments($r),
            ])->values(),
        ];
    }

    /**
     * Les passages d'une dictée, toutes parties à la suite
     *
     * @return list<array{debut: float, texte: string}>
     */
    private static function segments(EnregistrementConsultation $recording): array
    {
        $out = [];
        foreach ($recording->parties as $part) {
            foreach ($part->segments ?? [] as $segment) {
                $out[] = ['debut' => (float) ($segment['debut'] ?? 0), 'texte' => (string) ($segment['texte'] ?? '')];
            }
        }

        return $out;
    }
}
