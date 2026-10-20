<?php

namespace App\Http\Resources\V1;

use App\Models\Clinique;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La clinique vue de l'écran Organisation : identité, adresse,
 * rattachement à sa société, et son équipe (fiches RH complètes — la
 * même table `employes` que l'écran Équipe).
 *
 * @mixin Clinique
 */
class CliniqueResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'raison_sociale' => $this->raison_sociale,
            'societe_id' => $this->societe_id,
            'rue' => $this->rue,
            'npa' => $this->npa,
            'ville' => $this->ville,
            'latitude' => $this->latitude === null ? null : (float) $this->latitude,
            'longitude' => $this->longitude === null ? null : (float) $this->longitude,
            'tel' => $this->tel,
            'email' => $this->email,
            'fuseau_horaire' => $this->fuseau_horaire,
            'nombre_boxes' => $this->nombre_boxes,
            'photo_url' => $this->photoUrl(),
            'equipe' => EmployeResource::collection($this->whenLoaded('employes')),
            'nombre_employes_actifs' => $this->whenLoaded(
                'employes',
                fn (): int => $this->employes->where('acces_suspendu', false)->count(),
            ),
        ];
    }
}
