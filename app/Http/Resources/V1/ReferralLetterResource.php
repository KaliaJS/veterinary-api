<?php

namespace App\Http\Resources\V1;

use App\Enums\DomainePermission;
use App\Http\Controllers\Api\V1\ReferralInvitationController;
use App\Models\EnregistrementConsultation;
use App\Models\RecordShare;
use App\Models\RecordShareEvent;
use App\Models\ReferralInvitation;
use App\Models\ReferralLetter;
use App\Services\Referrals\ReferralLetters;
use App\Services\Referrals\ReferralLetterSources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * La lettre de référence (09.11.2026) : son état, ses pièces jointes, son
 * destinataire, les notes, le brouillon de l'IA (ou, avant lui, les sources
 * que le dossier donnera), le texte relu par rubrique, ce qui est recopié
 * (en direct avant la signature, figé après), la lettre telle que le
 * confrère la lit, la signature, le lien envoyé et ce qu'il en est (ouvert,
 * téléchargé).
 *
 * @mixin ReferralLetter
 */
class ReferralLetterResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $letters = app(ReferralLetters::class);
        // Ce qui est recopié, lu une seule fois : en direct avant la signature, figé après
        $copied = $this->isSigned() && is_array($this->copied) ? $this->copied : $letters->copied($this->resource);
        $share = $this->record_share_id ? RecordShare::withoutGlobalScopes()->with('events')->find($this->record_share_id) : null;

        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'patient_name' => $this->patient?->nom,
            'status' => $this->status,
            'error' => $this->error,
            'is_signed' => $this->isSigned(),
            'writings' => (int) $this->writings,
            'writings_max' => (int) config('transcription.redactions_max'),
            'items' => $this->items ?? [],
            'include_visit_notes' => (bool) $this->include_visit_notes,
            'recipient_name' => $this->recipient_name,
            'recipient_clinic' => $this->recipient_clinic,
            'recipient_email' => $this->recipient_email,
            'notes' => (string) $this->notes,
            'draft' => $this->draft,
            'preview_sources' => $this->draft === null && ! $this->isSigned() ? app(ReferralLetterSources::class)->for($this->resource) : null,
            'sections' => ReferralLetter::normalizeSections($this->sections),
            'edited_sections' => array_values($this->edited_sections ?? []),
            'edited_at' => (object) ($this->edited_at ?? []),
            'section_labels' => [...ReferralLetter::SECTION_LABELS, ...ReferralLetters::COPIED_LABELS],
            'order' => ReferralLetters::ORDER,
            'copied' => $copied,
            'blocks' => $letters->blocks($this->resource, $copied),
            // Les pièces ou les notes ont changé depuis ce brouillon : il ne les a pas lues
            'is_draft_stale' => $this->isDraftStale(),
            'written_at' => $this->written_at?->toIso8601String(),
            'signed_at' => $this->signed_at?->toIso8601String(),
            'signed_by_name' => $this->signed_by_name,
            'document_id' => $this->document_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'can_sign' => Gate::allows(DomainePermission::SignerComptesRendus->value),
            // Où en est la lettre : rédigée, signée, envoyée, ouverte
            'share' => $share ? [
                'id' => $share->id,
                'recipient_email' => $share->recipient_email,
                'expires_at' => $share->expires_at->toIso8601String(),
                'is_active' => $share->isActive(),
                'created_at' => $share->created_at?->toIso8601String(),
                'opened_at' => $share->events->firstWhere('kind', 'opened')?->at?->toIso8601String(),
                'downloaded_at' => $share->events->where('kind', 'downloaded')->map(fn (RecordShareEvent $e) => $e->at->toIso8601String())->values()->all(),
            ] : null,
            // Envoi C : où en est chaque invitation (envoyée, lue, acceptée, refusée, coupée)
            'invitations' => ReferralInvitation::query()->where('referral_letter_id', $this->id)->with('targetClinique')->latest('id')->get()
                ->map(fn (ReferralInvitation $i) => ReferralInvitationController::forSender($i))->values(),
            'dictations' => ReferralLetterSources::recordings((int) $this->id)->map(fn (EnregistrementConsultation $r) => [
                'id' => $r->id,
                'redige_le' => $r->redige_le?->toIso8601String(),
                'segments' => collect($r->parties)->flatMap(fn ($part) => array_map(fn (array $s) => ['debut' => (float) ($s['debut'] ?? 0), 'texte' => (string) ($s['texte'] ?? '')], $part->segments ?? []))->values()->all(),
            ])->values(),
        ];
    }
}
