<?php

namespace App\Http\Resources\V1;

use App\Models\CompteRendu;
use App\Models\CompteRenduComplement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CompteRendu
 */
class CompteRenduResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rendez_vous_id' => $this->rendez_vous_id,
            's' => $this->s,
            'o' => $this->o,
            'a' => $this->a,
            'p' => $this->p,
            // Lignes notées par les calculateurs (04.11.2026), à part du texte SOAP
            'tool_notes' => $this->tool_notes,
            'user_id' => $this->user_id,
            'redige_le' => $this->updated_at,
            // Contrôle de version (06.11.2026) : la version ouverte repart à l'enregistrement
            'version' => $this->version,
            'texte_modifie_le' => $this->texte_modifie_le,
            'modifie_par' => $this->whenLoaded('user', fn () => $this->user?->name),
            'signe_le' => $this->signe_le,
            // Le nom de la fiche d'équipe (un ancien membre devenu trace ne garde que son prénom)
            'signe_par' => $this->signataire->nom ?? null,
            'complements' => $this->whenLoaded('complements', fn () => $this->complements->map(fn (CompteRenduComplement $c) => [
                'id' => $c->id,
                'texte' => $c->texte,
                'signe_par' => $c->signataire->nom ?? null,
                'signe_le' => $c->created_at,
            ])->values()),
            'versions' => $this->whenCounted('versions'),
        ];
    }
}
