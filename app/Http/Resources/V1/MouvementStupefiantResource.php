<?php

namespace App\Http\Resources\V1;

use App\Models\MouvementStupefiant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une ligne du registre des stupéfiants : son type, sa quantité signée, le
 * stock après elle, et les noms tels qu'ils étaient à l'inscription
 * (01.11.2026). Une correction dit la ligne qu'elle corrige.
 *
 * @mixin MouvementStupefiant
 */
class MouvementStupefiantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clinique_id' => $this->clinique_id,
            'produit_id' => $this->produit_id,
            'patient_id' => $this->patient_id,
            'employe_id' => $this->employe_id,
            'type' => $this->type->value,
            'type_libelle' => $this->type->libelle(),
            'quantite' => (float) $this->quantite,
            'solde_apres' => $this->solde_apres === null ? null : (float) $this->solde_apres,
            'quantite_comptee' => $this->quantite_comptee === null ? null : (float) $this->quantite_comptee,
            'motif' => $this->motif,
            'patient_nom' => $this->patient_nom,
            'detenteur_nom' => $this->detenteur_nom,
            'signataire_nom' => $this->signataire_nom,
            'ordonnance_id' => $this->ordonnance_id,
            'ordonnance_numero' => $this->ordonnance_numero,
            'ordonnance_le' => $this->ordonnance_le?->toDateString(),
            'fournisseur' => $this->fournisseur,
            'bulletin' => $this->bulletin,
            'bulletin_le' => $this->bulletin_le?->toDateString(),
            'date_registre' => $this->date_registre?->toDateString(),
            'created_at' => $this->created_at,
            'corrige' => $this->whenLoaded('corrige', fn (): ?array => $this->corrige === null ? null : [
                'id' => $this->corrige->id,
                'type_libelle' => $this->corrige->type->libelle(),
                'quantite' => (float) $this->corrige->quantite,
                'created_at' => $this->corrige->created_at,
            ]),
            'produit' => $this->whenLoaded('produit', fn (): array => [
                'id' => $this->produit->id,
                'nom' => $this->produit->nom,
                'unite' => $this->produit->unite,
                'tableau' => $this->produit->tableau_stupefiant,
            ]),
        ];
    }
}
