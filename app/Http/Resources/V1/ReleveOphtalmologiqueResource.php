<?php

namespace App\Http\Resources\V1;

use App\Models\ReleveOphtalmologique;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReleveOphtalmologique
 */
class ReleveOphtalmologiqueResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'releve_le' => $this->releve_le->toDateString(),
            'schirmer_od' => $this->schirmer_od,
            'schirmer_og' => $this->schirmer_og,
            'pio_od' => $this->pio_od,
            'pio_og' => $this->pio_og,
            'fluoresceine' => $this->fluoresceine,
            'notes' => $this->notes,
        ];
    }
}
