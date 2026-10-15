<?php

namespace App\Http\Resources\V1;

use App\Models\ProduitEmballage;
use App\Models\ProduitVeterinaire;
use App\Models\Valence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Produit du catalogue (vaccin) pour la clinique : marque, valences en
 * français, espèces, schéma d emploi, emballages avec code-barres.
 *
 * @mixin ProduitVeterinaire
 */
class ProduitVeterinaireResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero_autorisation' => $this->numero_autorisation,
            'nom' => $this->nom_court,
            'nom_complet' => $this->nom,
            'titulaire' => $this->titulaire,
            'categorie' => $this->categorie,
            'atc_code' => $this->atc_code,
            'especes' => $this->especes ?? [],
            'voies' => $this->voies ?? [],
            'statut' => $this->statut,
            'retire_le' => $this->retire_le?->toDateString(),
            'valences' => $this->whenLoaded('valences', fn () => $this->valences->map(fn (Valence $v) => [
                'code' => $v->code, 'nom' => $v->nom, 'nom_court' => $v->nom_court,
            ])->values()->all()),
            'schema' => $this->whenLoaded('schema', fn () => $this->schema ? [
                'age_min_semaines' => $this->schema->age_min_semaines,
                'primo_injections' => $this->schema->primo_injections,
                'primo_intervalle_semaines' => $this->schema->primo_intervalle_semaines,
                'rappel_premier_mois' => $this->schema->rappel_premier_mois,
                'rappel_mois' => $this->schema->rappel_mois,
                'voie' => $this->schema->voie,
                'remarques' => $this->schema->remarques,
                'source' => $this->schema->source,
            ] : null),
            'emballages' => $this->whenLoaded('emballages', fn () => $this->emballages->map(fn (ProduitEmballage $e) => [
                'id' => $e->id, 'numero' => $e->numero_emballage, 'gtin' => $e->gtin, 'nom_fr' => $e->nom_fr,
                'taille' => $e->taille, 'retire_le' => $e->retire_le?->toDateString(),
            ])->values()->all()),
        ];
    }
}
