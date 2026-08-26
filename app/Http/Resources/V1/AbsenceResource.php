<?php

namespace App\Http\Resources\V1;

use App\Models\Absence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Absence
 */
class AbsenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employe_id' => $this->employe_id,
            'type' => $this->type,
            'debut_le' => $this->debut_le->toDateString(),
            'fin_le' => $this->fin_le->toDateString(),
            'statut' => $this->statut,
            'note' => $this->note,
            'valide_par_user_id' => $this->valide_par_user_id,
        ];
    }
}
