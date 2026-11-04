<?php

namespace App\Services;

use App\Auth\ContexteClinique;
use App\Enums\PieceNumerotee;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Enums\VatContext;
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
     * @param  list<array{libelle: string, quantite: int, prix_chf: float, type: TypeLigneFacture|string, produit_id?: int|null, vat_context?: VatContext|null, taux_tva?: float}>  $lignes
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
                // Le taux d'une déduction d'acompte vient d'ici (celui de l'acompte) ; tout autre taux se calcule à la naissance de la ligne
                $facture->lignes()->make(array_diff_key($ligne, ['taux_tva' => true]))->forceFill(['taux_tva' => $ligne['taux_tva'] ?? null])->save();
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

    /**
     * Acompte d'un devis accepté, montant du pourcentage. Il se répartit sur
     * les taux du devis, au prorata de ses montants TTC par taux (remise
     * déjà répartie) : une ligne par taux, chacune à son taux (vérification
     * du 04.11.2026). Un devis sans TVA (société non assujettie) : une ligne.
     */
    public function acompteDeDevis(Devis $devis, ?User $auteur): Facture
    {
        $libelle = "Acompte {$devis->acompte_pct} % : devis {$devis->numero}";
        $montant = $devis->montantAcompte();
        $parTaux = $devis->vatBreakdown();
        $base = array_sum(array_column($parTaux, 'incl_vat'));

        if (count($parTaux) === 0 || $base <= 0) {
            $lignes = [['libelle' => $libelle, 'quantite' => 1, 'prix_chf' => $montant, 'type' => TypeLigneFacture::Acte]];
        } else {
            $lignes = [];
            $reste = $montant;
            foreach ($parTaux as $i => $taux) {
                // Le dernier taux prend l'arrondi : la somme reste l'acompte
                $part = $i === count($parTaux) - 1 ? round($reste, 2) : round($montant * $taux['incl_vat'] / $base, 2);
                $reste -= $part;
                $lignes[] = [
                    'libelle' => count($parTaux) > 1 ? "{$libelle}, part à {$taux['rate']} %" : $libelle,
                    'quantite' => 1,
                    'prix_chf' => $part,
                    'type' => TypeLigneFacture::Acte,
                    'vat_context' => VatContext::Deposit,
                    'taux_tva' => $taux['rate'],
                ];
            }
        }

        $facture = $this->nouvelle([...$this->denormalisationDe($devis), 'stock_sorti' => true], $lignes, $auteur);

        $devis->update(['acompte_facture_id' => $facture->id]);

        return $facture;
    }

    /**
     * Facture finale d'un devis accepté : lignes recopiées, remise et
     * acompte déjà facturé déduits en lignes négatives (parité front).
     * Un devis chiffre un soin (bloc, intervention) : ses articles y sont
     * donnés et suivent le taux du soin, sauf ceux remis pour la maison, au
     * taux de vente ; la remise se répartit au prorata des taux, la
     * déduction de l'acompte reprend le taux de l'acompte (TVA par ligne,
     * 03.11.2026).
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
                'vat_context' => $ligne->vat_context ?? VatContext::Care,
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

        // Chaque ligne de l'acompte déduite à son propre taux (celui de la facture pour une ligne d'avant)
        if ($devis->acompte_facture_id !== null) {
            $acompte = $devis->acompteFacture;
            $parts = $acompte->lignes()->orderBy('id')->get();
            foreach ($parts as $part) {
                $taux = $part->vatRate($acompte) ?? (float) $acompte->taux_tva;
                $lignes[] = [
                    'libelle' => "Acompte déjà facturé ({$acompte->numero})".($parts->count() > 1 ? ", part à {$taux} %" : ''),
                    'quantite' => 1,
                    'prix_chf' => -round($part->quantite * (float) $part->prix_chf, 2),
                    'type' => TypeLigneFacture::Acte,
                    'vat_context' => VatContext::Deposit,
                    'taux_tva' => $taux,
                ];
            }
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
