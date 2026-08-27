<?php

namespace App\Services;

use App\Enums\TypeLigneFacture;
use App\Models\Facture;
use App\Models\Sejour;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Facture du séjour à la sortie (docs/data-model.md §8, hospBill du
 * front) : forfait journalier selon l'état clinique (65 / 95 /
 * 140 CHF/j), perfusion continue 38 CHF/j, soins réalisés 15 CHF/soin.
 * Tarifs de parité front — constants comme au front, hors catalogue
 * `prestations` (divergence signalée, pas absorbée). Les jours sont
 * les jours calendaires locaux couverts par le séjour.
 */
class FactureSejour
{
    public const float TARIF_PERFUSION = 38.0;

    public const float TARIF_SOIN = 15.0;

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
                'Hospitalisation box %s — %s, %d jour%s',
                $box,
                $sejour->statut->libelleFacturation(),
                $jours,
                $jours > 1 ? 's' : '',
            ),
            'quantite' => $jours,
            'prix_chf' => $sejour->statut->tarifJournalier(),
            'type' => TypeLigneFacture::Acte,
        ]];

        if ($sejour->perfusion !== null) {
            $lignes[] = [
                'libelle' => 'Perfusion continue — '.$sejour->perfusion,
                'quantite' => $jours,
                'prix_chf' => self::TARIF_PERFUSION,
                'type' => TypeLigneFacture::Acte,
            ];
        }

        $soinsFaits = $sejour->soins()->where('fait', true)->count();

        if ($soinsFaits > 0) {
            $lignes[] = [
                'libelle' => 'Soins infirmiers réalisés durant le séjour',
                'quantite' => $soinsFaits,
                'prix_chf' => self::TARIF_SOIN,
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
}
