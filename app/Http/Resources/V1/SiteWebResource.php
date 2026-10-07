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
            'domaine' => $this->domaineInfo(),
            'est_publie' => $this->est_publie,
            ...$this->apparence(),
            'couleurs' => SiteWeb::COULEURS,
            'accroche' => $this->accroche,
            'presentation' => $this->presentation,
            'tel_urgences' => $this->tel_urgences,
            'afficher_tel' => $this->afficher_tel,
            'afficher_email' => $this->afficher_email,
            'services' => array_values($this->services ?? []),
            'equipe' => $this->equipe(),
            'equipe_masquee' => array_values(array_map('intval', $this->equipe_masquee ?? [])),
            'actualites' => collect($this->actualites ?? [])->sortByDesc('date')->values()->all(),
            'organisation' => ['nom' => $this->organisation->nom],
            'cliniques' => $this->organisation->cliniques()->withoutGlobalScopes()->orderBy('cliniques.id')->get()
                ->map(fn (Clinique $clinique): array => [
                    'id' => $clinique->id,
                    'nom' => $clinique->nom,
                    'tel' => $clinique->tel,
                    'email' => $clinique->email,
                    'rue' => $clinique->rue,
                    'npa' => $clinique->npa,
                    'ville' => $clinique->ville,
                    // Pour l'aperçu de l'éditeur : mêmes horaires que le site public.
                    'horaires' => app(HorairesSiteWeb::class)->pour($clinique),
                ])->values()->all(),
        ];
    }
}
