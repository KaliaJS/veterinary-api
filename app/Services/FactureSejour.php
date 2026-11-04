<?php

namespace App\Services;

use App\Enums\TypeLigneFacture;
use App\Enums\VatContext;
use App\Models\Client;
use App\Models\Facture;
use App\Models\Prestation;
use App\Models\Sejour;
use App\Models\SejourSoin;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
     * @return list<array{libelle: string, quantite: int, prix_chf: float, type: TypeLigneFacture, vat_context?: VatContext}>
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

        // Les soins infirmiers : les soins faits, pas les prises de médicament (décision de Jim, 03.11.2026)
        // Jamais ce qui a été noté après la sortie (neuvième vérification)
        $soinsFaits = $sejour->soins()->where('fait', true)->whereNull('cancelled_at')->where('is_unbilled', false)->whereNull('treatment_id')->count();

        if ($soinsFaits > 0) {
            $lignes[] = [
                'libelle' => 'Soins infirmiers réalisés durant le séjour',
                'quantite' => $soinsFaits,
                'prix_chf' => $this->prix('hosp_soin'),
                'type' => TypeLigneFacture::Acte,
            ];
        }

        return [...$lignes, ...$this->medicationLines($sejour)];
    }

    /**
     * Les médicaments à la dose (décision de Jim, 03.11.2026) : par
     * traitement, la dose donnée en tout, au prix de vente d'une unité du
     * produit divisé par sa contenance. Sans produit lié au prix connu, rien
     * n'est facturé. Pas de produit sur la ligne : chaque prise a déjà sorti
     * sa dose du stock, l'encaissement ne la ressort pas.
     *
     * @return list<array{libelle: string, quantite: int, prix_chf: float, type: TypeLigneFacture, vat_context?: VatContext}>
     */
    private function medicationLines(Sejour $sejour): array
    {
        $doses = $sejour->soins()->where('fait', true)->whereNull('cancelled_at')->where('is_unbilled', false)->whereNotNull('treatment_id')->whereNotNull('produit_id')->whereNotNull('dose_amount')
            ->with(['produit', 'treatment'])->get()
            // Par traitement et par unité figée sur la prise : jamais deux unités additionnées (sixième vérification)
            ->groupBy(fn (SejourSoin $p): string => $p->treatment_id.'|'.($p->dose_unit ?? $p->produit?->dose_unit));

        $lines = [];
        foreach ($doses as $group) {
            $product = $group->first()->produit;
            // Le prix et la contenance du jour de chaque prise (deuxième vérification, 03.11.2026)
            $amount = $group->sum(function (SejourSoin $p) use ($product): float {
                $content = (float) ($p->content_per_unit ?? $product?->content_per_unit);
                $price = (float) ($p->unit_price ?? $product?->prix);

                return $content > 0 ? (float) $p->dose_amount * $price / $content : 0.0;
            });
            if ($product === null || $amount <= 0) {
                continue;
            }
            $dose = round((float) $group->sum('dose_amount'), 3);
            $treatment = $group->first()->treatment;
            $name = trim(($treatment->label ?? $group->first()->acte).' '.($treatment->dose ?? ''));
            $lines[] = [
                'libelle' => sprintf('%s (%s), %s %s en %d prise%s', $name, $product->nom, self::formatNumber($dose), $group->first()->dose_unit ?? $product->dose_unit, $group->count(), $group->count() > 1 ? 's' : ''),
                'quantite' => 1,
                'prix_chf' => round($amount, 2),
                'type' => TypeLigneFacture::Medicament,
                // Donné pendant le séjour : le taux du soin (TVA par ligne, 03.11.2026)
                'vat_context' => VatContext::Care,
            ];
        }

        return $lines;
    }

    /** « 4 », « 1,5 » */
    private static function formatNumber(float $n): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.'));
    }

    /**
     * Sortie : le séjour est clos (box libéré), jamais supprimé, et sa
     * facture naît en caisse — un seul geste, une seule fois.
     */
    public function sortir(Sejour $sejour, ?User $auteur): Facture
    {
        return DB::transaction(function () use ($sejour, $auteur): Facture {
            // Sous verrou : une annulation de prise au même instant attend, puis voit la sortie
            if (Sejour::withoutGlobalScopes()->whereKey($sejour->id)->lockForUpdate()->value('sorti_le') !== null) {
                throw ValidationException::withMessages(['sejour' => 'Ce séjour est déjà sorti.']);
            }
            $sejour->update(['sorti_le' => now()]);

            $facture = $this->creationFacture->nouvelle(
                [
                    'patient_id' => $sejour->patient_id,
                    ...$sejour->patient->denormalisation(),
                    // Au propriétaire qui a confié l'animal, pas au propriétaire actuel
                    // (deuxième vérification, 03.11.2026) : la dette suit la facture
                    ...$this->admissionOwner($sejour),
                ],
                $this->lignes($sejour),
                $auteur,
            );

            $sejour->update(['facture_id' => $facture->id]);

            return $facture;
        });
    }

    /**
     * Le propriétaire de l'admission (`sejours.client_id`), s'il est connu.
     *
     * @return array<string, mixed>
     */
    private function admissionOwner(Sejour $sejour): array
    {
        $client = $sejour->client_id !== null ? Client::query()->find($sejour->client_id) : null;

        return $client === null ? [] : ['client_id' => $client->id, 'owner_name' => $client->nom_affiche];
    }

    /** Prix courant de l'acte au catalogue de l'organisation. */
    private function prix(string $code): float
    {
        return (float) Prestation::where('code', $code)->firstOrFail()->prix;
    }
}
