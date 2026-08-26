<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Forme attendue par le front (api/auth.js) : { mail, first, last }.
 *
 * @mixin User
 */
class UtilisateurResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'mail' => $this->email,
            'first' => $this->prenom(),
            'last' => $this->nomFamille(),
        ];
    }
}
