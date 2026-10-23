<?php

namespace App\Services;

use App\Enums\TypeLigneFacture;
use App\Models\Facture;
use App\Models\Prestation;
use App\Models\Sejour;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Facture du séjour à la sortie (docs/data-model.md §8, hospBill du
 * front) : forfait journalier selon l'état clinique, perfusion
 * continue et soins réalisés. Les prix viennent du catalogue
 * `prestations` de l'organisation (hosp_stable / hosp_surv /
 * hosp_critique / hosp_perfusion / hosp_soin) — plus jamais de
 * constantes PHP : deux catalogues divergents ont déjà produit deux
 * prix pour les mêmes actes. Les jours sont les jours calendaires
 * locaux couverts par le séjour.
 */
class FactureSejour
{
    public function __construct(private CreationFacture $creationFacture) {}

    /**
     * Lignes dérivées de l'état du séjour — servies aussi en aperçu
     * avant la sortie.
     *
     * @return list<array{libelle: string, quantite: int, prix_chf: float, type: TypeLigneFacture}>
     */
    public function lignes(Sejour $sejour): array
    {
        $jours = $sejour->joursFactures();
        $box = str_pad((string) $sejour->box, 2, '0', STR_PAD_LEFT);

        $lignes = [[
            'libelle' => sprintf(
                'Hospitalisation box %s, %s, %d jour%s',
                $box,
                $sejour->statut->libelleFacturation(),
                $jours,
                $jours > 1 ? 's' : '',
            ),
            'quantite' => $jours,
            'prix_chf' => $this->prix($sejour->statut->codePrestation()),
            'type' => TypeLigneFacture::Acte,
        ]];

        if ($sejour->perfusion !== null) {
            $lignes[] = [
                'libelle' => 'Perfusion continue : '.$sejour->perfusion,
                'quantite' => $jours,
                'prix_chf' => $this->prix('hosp_perfusion'),
                'type' => TypeLigneFacture::Acte,
            ];
        }

        $soinsFaits = $sejour->soins()->where('fait', true)->count();

        if ($soinsFaits > 0) {
            $lignes[] = [
                'libelle' => 'Soins infirmiers réalisés durant le séjour',
                'quantite' => $soinsFaits,
                'prix_chf' => $this->prix('hosp_soin'),
                'type' => TypeLigneFacture::Acte,
            ];
        }

        return $lignes;
    }

    /**
     * Sortie : le séjour est clos (box libéré), jamais supprimé, et sa
     * facture naît en caisse — un seul geste, une seule fois.
     */
    public function sortir(Sejour $sejour, ?User $auteur): Facture
    {
        return DB::transaction(function () use ($sejour, $auteur): Facture {
            $sejour->update(['sorti_le' => now()]);

            $facture = $this->creationFacture->nouvelle(
                [
                    'patient_id' => $sejour->patient_id,
                    ...$sejour->patient->denormalisation(),
                ],
                $this->lignes($sejour),
                $auteur,
            );

            $sejour->update(['facture_id' => $facture->id]);

            return $facture;
        });
    }

    /** Prix courant de l'acte au catalogue de l'organisation. */
    private function prix(string $code): float
    {
        return (float) Prestation::where('code', $code)->firstOrFail()->prix;
    }
}
