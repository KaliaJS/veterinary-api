<?php

namespace App\Http\Resources\V1;

use App\Models\Ordonnance;
use App\Models\OrdonnanceLigne;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Le signataire sort par son nom (l'employé peut être d'une clinique
 * sœur — son scope est levé au chargement, comme pour le dossier
 * médical).
 *
 * @mixin Ordonnance
 */
class OrdonnanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'clinique_id' => $this->clinique_id,
            'patient_id' => $this->patient_id,
            'employe_id' => $this->employe_id,
            'signataire' => $this->whenLoaded('employe', fn (): string => $this->employe->nom),
            'signee_le' => $this->signee_le->toDateString(),
            'renouvellements' => $this->renouvellements,
            'statut' => $this->statut,
            'note' => $this->note,
            'ordonnance_precedente_id' => $this->ordonnance_precedente_id,
            // Le propriétaire a demandé le renouvellement depuis son espace (encore à traiter)
            'renouvellement_demande_le' => $this->whenLoaded('demandesRenouvellement', fn (): ?string => $this->demandesRenouvellement
                ->whereNull('traitee_le')
                ->sortBy('created_at')
                ->first()?->created_at?->toIso8601String()),
            // En-tête de la clinique qui l'a établie (imprimée depuis une clinique sœur), 24.10.2026
            'en_tete' => $this->whenLoaded('clinique', fn (): array => $this->clinique->enTeteDocument()),
            'lignes' => $this->whenLoaded(
                'lignes',
                fn () => $this->lignes->map(fn (OrdonnanceLigne $ligne): array => [
                    'id' => $ligne->id,
                    'produit_id' => $ligne->produit_id,
                    'produit_nom' => $ligne->relationLoaded('produit') ? $ligne->produit?->nom : null,
                    'quantite' => $ligne->quantite,
                    'posologie' => $ligne->posologie,
                    'duree' => $ligne->duree,
                ])->values(),
            ),
            'patient' => new PatientResource($this->whenLoaded('patient')),
            // L'ordonnance telle qu'elle s'imprime (A5, 01.11.2026)
            'document' => $this->when(
                $this->relationLoaded('patient') && $this->relationLoaded('employe') && $this->relationLoaded('clinique'),
                fn (): array => $this->document(),
            ),
        ];
    }
}
