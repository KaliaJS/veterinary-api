<?php

namespace App\Http\Resources\V1;

use App\Models\Clinique;
use App\Models\FactureProprietaire;
use App\Models\Organisation;
use App\Models\OrganisationFacture;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Facture de la plateforme (Meteora Systems Sàrl), à une organisation ou à
 * un propriétaire : numéro, lignes, période, paiement. En détail (`avecEntetes`),
 * l'émetteur et le client pour imprimer la facture (A4, dans le navigateur).
 * Sans TVA : l'émetteur n'y est pas assujetti.
 *
 * @mixin OrganisationFacture|FactureProprietaire
 */
class FacturePlateformeResource extends JsonResource
{
    private bool $entetes = false;

    public function avecEntetes(): static
    {
        $this->entetes = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'emise_le' => $this->emise_le->toDateString(),
            'libelle' => $this->libelle,
            'statut' => $this->statut,
            'montant' => (float) $this->montant,
            'lignes' => $this->lignes ?? [['libelle' => $this->libelle, 'montant' => (float) $this->montant]],
            'periode_debut' => $this->periode_debut?->toDateString(),
            'periode_fin' => $this->periode_fin?->subDay()->toDateString(),
            'paye_le' => $this->paye_le?->toIso8601String(),
            'carte' => $this->carte,
            // Paiement envoyé à Stripe sans réponse encore : renvoyé chaque minute (26.10.2026)
            'en_verification' => $this->statut === 'a_payer' && isset($this->metadonnees['demande_en_suspens']),
            // Factures Stripe d'avant le 20.10.2026 : leur PDF reste chez Stripe
            'pdf_url' => $this->lignes === null ? $this->pdf_url : null,
            ...($this->entetes ? ['emetteur' => $this->emetteur(), 'client' => $this->client()] : []),
        ];
    }

    /** @return array<string, mixed> */
    private function emetteur(): array
    {
        $e = config('plateforme.emetteur');

        return [
            'nom' => $e['nom'],
            'marque' => $e['marque'],
            'lignes' => array_values(array_filter([$e['rue'], trim($e['npa'].' '.$e['ville']), $e['email']])),
            'ide' => $e['ide'],
            'assujetti_tva' => (bool) $e['assujetti_tva'],
        ];
    }

    /** @return array{nom: string, lignes: list<string>} */
    private function client(): array
    {
        return $this->resource instanceof FactureProprietaire
            ? $this->proprietaire($this->resource)
            : $this->organisation($this->resource);
    }

    /** @return array{nom: string, lignes: list<string>} */
    private function proprietaire(FactureProprietaire $facture): array
    {
        $user = User::query()->find($facture->user_id);

        return ['nom' => (string) $user?->name, 'lignes' => array_filter([$user?->email])];
    }

    /** @return array{nom: string, lignes: list<string>} */
    private function organisation(OrganisationFacture $facture): array
    {
        $organisation = Organisation::query()->with('societes')->find($facture->organisation_id);
        $societe = $organisation?->societes->first();
        $clinique = $societe ? Clinique::withoutGlobalScopes()->where('societe_id', $societe->id)->orderBy('id')->first() : null;

        return [
            'nom' => (string) ($societe?->nom ?: $organisation?->nom),
            'lignes' => array_values(array_filter([
                $societe && $societe->nom !== $organisation->nom ? $organisation->nom : null,
                $clinique?->rue,
                $clinique ? trim($clinique->npa.' '.$clinique->ville) : null,
                $societe?->ide ? 'IDE '.$societe->ide : null,
            ])),
        ];
    }
}
