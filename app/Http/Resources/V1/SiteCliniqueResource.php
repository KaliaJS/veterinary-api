<?php

namespace App\Http\Resources\V1;

use App\Models\SiteClinique;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Site de la clinique pour l'éditeur (application clinique). Les
 * actualités sortent de la plus récente à la plus ancienne.
 *
 * @mixin SiteClinique
 */
class SiteCliniqueResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'est_publie' => $this->est_publie,
            'couleur' => $this->couleur,
            'couleurs' => SiteClinique::COULEURS,
            'accroche' => $this->accroche,
            'presentation' => $this->presentation,
            'email' => $this->email,
            'tel_urgences' => $this->tel_urgences,
            'services' => array_values($this->services ?? []),
            'equipe' => array_values($this->equipe ?? []),
            'actualites' => collect($this->actualites ?? [])->sortByDesc('date')->values()->all(),
            'clinique' => [
                'nom' => $this->clinique->nom,
                'tel' => $this->clinique->tel,
                'rue' => $this->clinique->rue,
                'npa' => $this->clinique->npa,
                'ville' => $this->clinique->ville,
            ],
        ];
    }
}
