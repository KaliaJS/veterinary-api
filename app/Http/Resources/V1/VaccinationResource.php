<?php

namespace App\Http\Resources\V1;

use App\Models\Vaccination;
use App\Services\EtatVaccinal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une injection du carnet : produit du catalogue (ou saisie libre), valences
 * au moment de l injection, lot, péremption, voie, site, vétérinaire,
 * dates. Le statut d échéance est un dérivé d affichage calculé à part
 * (EtatVaccinal) ; `jours` reste fourni pour l historique.
 *
 * @mixin Vaccination
 */
class VaccinationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $valences = EtatVaccinal::valencesDe($this->resource);

        return [
            'id' => $this->id,
            'produit_id' => $this->produit_id,
            'produit' => $this->whenLoaded('produit', fn () => $this->produit ? [
                'id' => $this->produit->id, 'nom' => $this->produit->nom_court, 'titulaire' => $this->produit->titulaire,
            ] : null),
            'nom' => $this->nom,
            'marque' => $this->marque,
            'valences' => array_values(array_map(fn (string $code, array $v) => ['code' => $code, 'nom' => $v['nom'], 'nom_court' => $v['court']], array_keys($valences), $valences)),
            'lot' => $this->lot,
            'gtin' => $this->gtin,
            'peremption_le' => $this->peremption_le?->toDateString(),
            'voie' => $this->voie,
            'site_injection' => $this->site_injection,
            'remarque' => $this->remarque,
            'reaction' => $this->reaction,
            'employe_id' => $this->employe_id,
            'veterinaire' => $this->whenLoaded('employe', fn () => $this->employe?->nom),
            'injectee_le' => $this->injectee_le?->toDateString(),
            'echeance_le' => $this->echeance_le?->toDateString(),
            'jours' => $this->echeance_le ? (int) today()->diffInDays($this->echeance_le, false) : null,
        ];
    }
}
