<?php

namespace App\Http\Resources\V1;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Enums\TypeAbsence;
use App\Models\Absence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * @mixin Absence
 */
class AbsenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // Sans le droit Équipe, l'absence d'un autre se dit sans sa raison ni
        // sa note (une maladie est une donnée de santé, 11.11.2026) ; une garde
        // n'est pas une raison et reste dite
        $estMasquee = ! Gate::allows(DomainePermission::Equipe->value)
            && $this->employe_id !== app(ContexteClinique::class)->employe()?->id;

        return [
            'id' => $this->id,
            'employe_id' => $this->employe_id,
            'type' => $estMasquee && $this->type !== TypeAbsence::Garde ? null : $this->type,
            'debut_le' => $this->debut_le->toDateString(),
            'fin_le' => $this->fin_le->toDateString(),
            'statut' => $this->statut,
            'note' => $estMasquee ? null : $this->note,
            'valide_par_user_id' => $this->valide_par_user_id,
        ];
    }
}
