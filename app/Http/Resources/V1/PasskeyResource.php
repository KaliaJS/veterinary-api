<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Passkeys\Passkey;

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
            'nom' => $this->name,
            'dernier_usage_le' => $this->last_used_at?->toIso8601String(),
            'cree_le' => $this->created_at?->toIso8601String(),
        ];
    }
}
