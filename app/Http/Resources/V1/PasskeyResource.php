<?php

namespace App\Http\Resources\V1;

use App\Models\Passkey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Passkey
 */
class PasskeyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'dernier_usage_le' => $this->dernier_usage_le?->toIso8601String(),
            'cree_le' => $this->created_at?->toIso8601String(),
        ];
    }
}
