<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Forme attendue par le front (api/auth.js) : { mail, first, last },
 * plus l'identifiant du compte.
 *
 * @mixin User
 */
class UtilisateurResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mail' => $this->email,
            'first' => $this->prenom(),
            'last' => $this->nomFamille(),
            // À donner à une clinique pour s'y faire embaucher
            'code_employe' => User::formaterCodeEmploye($this->codeEmploye()),
            // Ouvre l'espace Plateforme (vérifications)
            'est_moderateur' => (bool) $this->est_moderateur,
            // Sans passkey, le front en propose une après une connexion par code
            'a_une_passkey' => $this->passkeys()->exists(),
        ];
    }
}
