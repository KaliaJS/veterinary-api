<?php

namespace App\Http\Resources\V1;

use App\Enums\StatutSuggestionDiagnostique;
use App\Models\SuggestionDiagnostique;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une suggestion vue par le vétérinaire : où elle en est, le compte rendu
 * dont elle part (pour savoir s'il a changé depuis) et, rendue, son
 * contenu et son prix.
 *
 * @mixin SuggestionDiagnostique
 */
class SuggestionDiagnostiqueResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $prete = $this->statut === StatutSuggestionDiagnostique::Prete;

        return [
            'id' => $this->id,
            'rendez_vous_id' => $this->rendez_vous_id,
            'statut' => $this->statut->value,
            'soap' => $this->soap,
            'avec_transcription' => $this->avec_transcription,
            'contenu' => $prete ? $this->contenu : null,
            'montant' => $this->montant_centimes / 100,
            'auteur' => $this->user?->name,
            'demandee_le' => $this->created_at,
            'rendue_le' => $this->rendue_le,
        ];
    }
}
