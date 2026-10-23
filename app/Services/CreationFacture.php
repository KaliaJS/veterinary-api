<?php

namespace App\Services;

use App\Auth\ContexteClinique;
use App\Enums\PieceNumerotee;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\Prestation;
use App\Models\RendezVous;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Toute création de facture passe ici : numérotation FC, clinique
 * d'émission du jeton, auteur mémorisé pour la fenêtre d'annulation.
 * Le passage d'un RDV en « fini » facture l'acte dérivé de son type
 * (une seule facture par RDV — la réouverture retrouve l'existante) ;
 * un devis accepté se facture en acompte puis en finale (remise et
 * acompte déduits en lignes négatives).
 */
class CreationFacture
{
    public function __construct(private Numeroteur $numeroteur) {}

    /**
     * @param  array<string, mixed>  $attributs
     * @param  list<array{libelle: string, quantite: int, prix_chf: float, type: TypeLigneFacture|string, produit_id?: int|null}>  $lignes
     */
    public function nouvelle(array $attributs, array $lignes, ?User $auteur): Facture
    {
        return DB::transaction(function () use ($attributs, $lignes, $auteur): Facture {
            // Le rendez-vous réservé en ligne avec un acompte : il est déduit
            if (! empty($attributs['rendez_vous_id']) && ! isset($attributs['acompte'])) {
                $attributs['acompte'] = RendezVous::query()->find($attributs['rendez_vous_id'])?->demandeEnLigne?->acompteRetenu() ?? 0;
            }

            $facture = Facture::create([
                'clinique_id' => app(ContexteClinique::class)->cliniqueId(),
                'numero' => $this->numeroteur->attribuer(PieceNumerotee::Facture),
                'emise_le' => now(),
                'statut' => StatutFacture::Due,
                'cree_par_id' => $auteur?->id,
                ...$attributs,
            ]);

            foreach ($lignes as $ligne) {
                $facture->lignes()->create($ligne);
            }

            return $facture;
        });
    }

    /**
     * RDV « fini » → facture de l'acte dérivé du type (TYPE_ACTE du
     * front : le code de la prestation est le code du type, repli sur
     * la consultation standard). Une seule facture par rendez-vous ;
     * un tampon n'est jamais facturé.
     */
    public function depuisRendezVous(RendezVous $rendezVous, ?User $auteur): ?Facture
    {
        if ($rendezVous->estTampon()) {
            return null;
        }

        $existante = Facture::where('rendez_vous_id', $rendezVous->id)->first();

        if ($existante !== null) {
            return $existante;
        }

        $prestation = Prestation::where('code', $rendezVous->type->value)->first()
            ?? Prestation::where('code', 'consult')->firstOrFail();

        return $this->nouvelle(
            [
                'patient_id' => $rendezVous->patient_id,
                'client_id' => $rendezVous->client_id,
                'patient_name' => $rendezVous->patient_name,
                'species' => $rendezVous->species,
                'owner_name' => $rendezVous->owner_name,
                'ligne_planning_id' => $rendezVous->ligne_planning_id,
                'rendez_vous_id' => $rendezVous->id,
            ],
            [[
                'libelle' => $prestation->nom,
                'quantite' => 1,
                'prix_chf' => (float) $prestation->prix,
                'type' => TypeLigneFacture::Acte,
            ]],
            $auteur,
        );
    }

    /** Acompte d'un devis accepté — une ligne, montant du pourcentage. */
    public function acompteDeDevis(Devis $devis, ?User $auteur): Facture
    {
        $facture = $this->nouvelle(
            [...$this->denormalisationDe($devis), 'stock_sorti' => true],
            [[
                'libelle' => "Acompte {$devis->acompte_pct} % : devis {$devis->numero}",
                'quantite' => 1,
                'prix_chf' => $devis->montantAcompte(),
                'type' => TypeLigneFacture::Acte,
            ]],
            $auteur,
        );

        $devis->update(['acompte_facture_id' => $facture->id]);

        return $facture;
    }

    /**
     * Facture finale d'un devis accepté : lignes recopiées, remise et
     * acompte déjà facturé déduits en lignes négatives (parité front).
     */
    public function finaleDeDevis(Devis $devis, ?User $auteur): Facture
    {
        $lignes = $devis->lignes()->get()
            ->map(fn ($ligne): array => [
                'libelle' => $ligne->libelle,
                'quantite' => $ligne->quantite,
                'prix_chf' => (float) $ligne->prix_chf,
                'type' => $ligne->type,
                'produit_id' => $ligne->produit_id,
            ])
            ->all();

        if ($devis->remise_pct > 0) {
            $lignes[] = [
                'libelle' => "Remise {$devis->remise_pct} %",
                'quantite' => 1,
                'prix_chf' => -round($devis->sousTotal() * $devis->remise_pct / 100, 2),
                'type' => TypeLigneFacture::Acte,
            ];
        }

        if ($devis->acompte_facture_id !== null) {
            $lignes[] = [
                'libelle' => "Acompte déjà facturé ({$devis->acompteFacture->numero})",
                'quantite' => 1,
                'prix_chf' => -$devis->montantAcompte(),
                'type' => TypeLigneFacture::Acte,
            ];
        }

        $facture = $this->nouvelle(
            [...$this->denormalisationDe($devis), 'stock_sorti' => true],
            $lignes,
            $auteur,
        );

        $devis->update(['facture_finale_id' => $facture->id]);

        return $facture;
    }

    /** @return array<string, mixed> */
    private function denormalisationDe(Devis $devis): array
    {
        return [
            'patient_id' => $devis->patient_id,
            ...$devis->patient->denormalisation(),
        ];
    }
}
