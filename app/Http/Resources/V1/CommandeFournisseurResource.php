<?php

namespace App\Http\Resources\V1;

use App\Auth\ContexteClinique;
use App\Models\CommandeFournisseur;
use App\Models\CommandeFournisseurLigne;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CommandeFournisseur
 */
class CommandeFournisseurResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // Les lignes lues une fois : le total et leur nombre sont servis, jamais recalculés à l'écran
        $lignes = $this->resource->loadMissing('lignes')->lignes;
        $centimes = fn (CommandeFournisseurLigne $ligne): int => $ligne->quantite * (int) round((float) $ligne->cout * 100);

        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'clinique_id' => $this->clinique_id,
            'fournisseur' => $this->fournisseur,
            'statut' => $this->statut,
            'envoyee_le' => $this->envoyee_le,
            'receptionnee_le' => $this->receptionnee_le,
            'creee_le' => $this->created_at,
            // Au centime : somme des quantités × coûts des lignes (03.11.2026)
            'total_chf' => $lignes->sum($centimes) / 100,
            'nombre_lignes' => $lignes->count(),
            // Le mois de réception à l'heure de la clinique : le titre de mois de la liste
            'mois' => $this->receptionnee_le?->copy()->setTimezone($this->fuseau())->format('Y-m'),
            // Envoyée (ou en route) par Vetpoint (28.10.2026)
            'vetpoint' => $this->vetpoint_commande_id !== null || $this->vetpoint_envoi_le !== null ? [
                'numero' => $this->vetpoint_commande_id,
                'livraison' => $this->vetpoint_livraison,
                'date_souhaitee' => $this->vetpoint_date_souhaitee?->toDateString(),
                'envoi_en_cours' => $this->vetpoint_commande_id === null,
            ] : null,
            // Réception d'une commande Vetpoint : prix réels lus ou non (28.10.2026)
            'reception_vetpoint' => $this->when($this->resource->receptionVetpoint !== null, fn () => $this->resource->receptionVetpoint),
            'lignes' => $this->whenLoaded(
                'lignes',
                fn () => $this->lignes->map(fn (CommandeFournisseurLigne $ligne): array => [
                    'id' => $ligne->id,
                    'produit_id' => $ligne->produit_id,
                    'quantite' => $ligne->quantite,
                    'cout' => (float) $ligne->cout,
                    'total_chf' => $centimes($ligne) / 100,
                ])->values(),
            ),
        ];
    }

    /** Le fuseau de la clinique de la commande ; la clinique ouverte, déjà chargée, d'abord */
    private function fuseau(): string
    {
        $ouverte = app(ContexteClinique::class)->clinique();
        $clinique = $ouverte !== null && $ouverte->id === $this->clinique_id ? $ouverte : $this->clinique;

        return $clinique?->fuseau_horaire ?: 'Europe/Zurich';
    }
}
