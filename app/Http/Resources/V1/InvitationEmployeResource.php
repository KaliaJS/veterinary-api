<?php

namespace App\Http\Resources\V1;

use App\Models\InvitationEmploye;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une invitation vue de l'équipe : à qui, pour quel poste et quelles
 * cliniques, jusqu'à quand, et par qui.
 *
 * @mixin InvitationEmploye
 */
class InvitationEmployeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'nom' => $this->nom,
            'poste' => $this->donnees['poste'] ?? null,
            'toutes_les_cliniques' => (bool) ($this->donnees['toutes_les_cliniques'] ?? false),
            'cliniques' => array_map('intval', $this->donnees['cliniques'] ?? []),
            'invitee_par' => $this->invitant?->nom,
            'expire_le' => $this->expire_le->toIso8601String(),
            'envoyee_le' => $this->updated_at->toIso8601String(),
        ];
    }
}
