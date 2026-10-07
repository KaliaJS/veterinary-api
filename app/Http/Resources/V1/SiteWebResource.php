<?php

namespace App\Http\Resources\V1;

use App\Models\Clinique;
use App\Models\SiteWeb;
use App\Services\HorairesSiteWeb;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Site de l'organisation pour l'éditeur (application clinique). Les
 * actualités sortent de la plus récente à la plus ancienne ; les
 * cliniques présentées sont celles de l'organisation, lues en direct,
 * avec leurs horaires mis en forme comme sur le site (aperçu).
 *
 * @mixin SiteWeb
 */
class SiteWebResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'est_publie' => $this->est_publie,
            ...$this->apparence(),
            'couleurs' => SiteWeb::COULEURS,
            'accroche' => $this->accroche,
            'presentation' => $this->presentation,
            'email' => $this->email,
            'tel_urgences' => $this->tel_urgences,
            'services' => array_values($this->services ?? []),
            'equipe' => array_values($this->equipe ?? []),
            'actualites' => collect($this->actualites ?? [])->sortByDesc('date')->values()->all(),
            'organisation' => ['nom' => $this->organisation->nom],
            'cliniques' => $this->organisation->cliniques()->withoutGlobalScopes()->orderBy('cliniques.id')->get()
                ->map(fn (Clinique $clinique): array => [
                    'id' => $clinique->id,
                    'nom' => $clinique->nom,
                    'tel' => $clinique->tel,
                    'rue' => $clinique->rue,
                    'npa' => $clinique->npa,
                    'ville' => $clinique->ville,
                    // Pour l'aperçu de l'éditeur : mêmes horaires que le site public.
                    'horaires' => app(HorairesSiteWeb::class)->pour($clinique),
                ])->values()->all(),
        ];
    }
}
