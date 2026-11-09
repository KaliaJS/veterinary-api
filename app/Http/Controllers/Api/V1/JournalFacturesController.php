<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\VatRate;
use App\Http\Controllers\Controller;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Settlement;
use App\Support\VatBreakdown;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Journal d'un mois de la clinique du jeton (23.10.2026) : les factures
 * émises (journal des ventes, CSV) et les encaissements avec leurs
 * totaux (décompte TVA). Tout est servi, pages comprises : le front ne
 * recompose plus un mois à partir des cent dernières factures chargées.
 * Le mois se compte à l'heure de la clinique.
 */
class JournalFacturesController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Facture::class);

        $mois = $request->validate(['mois' => ['required', 'date_format:Y-m']])['mois'];

        $contexte = app(ContexteClinique::class);
        $fuseau = $contexte->clinique()?->fuseau_horaire ?: 'Europe/Zurich';
        $debut = CarbonImmutable::parse($mois.'-01', $fuseau);
        $fin = $debut->addMonth();
        [$du, $au] = [$debut->utc(), $fin->utc()];

        $base = Facture::query()
            ->with(['lignes', 'settlements'])
            ->where('clinique_id', $contexte->cliniqueId())
            ->orderBy('emise_le')
            ->orderBy('id');

        $emises = (clone $base)->whereBetween('emise_le', [$du, $au->subSecond()])->get();
        // Les encaissements du mois : chaque règlement reçu dans le mois (09.11.2026),
        // daté du jour où l'argent est arrivé, annulations et remboursements compris
        $reglements = Settlement::query()
            ->where('clinique_id', $contexte->cliniqueId())
            ->whereBetween('received_on', [$debut->toDateString(), $fin->subDay()->toDateString()])
            ->with(['facture.lignes', 'facture.settlements', 'recordedBy'])
            ->orderBy('received_on')
            ->orderBy('id')
            ->get();
        $lignesEncaissees = $reglements->map(fn (Settlement $s): array => $this->reglement($s));

        return response()->json(['data' => [
            'mois' => $mois,
            'taux_tva' => $contexte->clinique()?->societe?->tauxTva() ?? VatRate::Normal->percent(),
            // Les taux en vigueur, toujours tous : une colonne par taux dans l'export, même vide (03.11.2026)
            'vat_rates' => collect(VatRate::cases())->map(fn (VatRate $rate): float => $rate->percent())->sort()->values()->all(),
            'emises' => $emises->map(fn (Facture $facture): array => $this->ligne($facture))->values(),
            'encaissements' => [
                'reglements' => $lignesEncaissees->values(),
                'nombre' => $lignesEncaissees->count(),
                'total_ttc' => round((float) $lignesEncaissees->sum('montant'), 2),
                'montant_tva' => round((float) $lignesEncaissees->sum('montant_tva'), 2),
                'total_ht' => round((float) $lignesEncaissees->sum('montant') - (float) $lignesEncaissees->sum('montant_tva') - (float) $lignesEncaissees->sum('excedent'), 2),
                // Reçu en plus du total des factures (vérification du 09.11.2026), hors chiffre d'affaires
                'excedent' => round((float) $lignesEncaissees->sum('excedent'), 2),
                // Le décompte TVA taux par taux, chaque règlement avec sa part de TVA (03.11 et 09.11.2026)
                'vat_breakdown' => VatBreakdown::sum($lignesEncaissees->pluck('vat_breakdown')),
            ],
        ]]);
    }

    /**
     * Un règlement du journal : sa part de la TVA de la facture, au prorata
     * de son montant (une facture payée en deux fois a sa TVA en deux
     * parts ; méthode des contre-prestations reçues).
     *
     * @return array<string, mixed>
     */
    private function reglement(Settlement $s): array
    {
        $facture = $s->facture;
        $total = $facture->totalTtc();
        // La TVA ne porte que sur ce qui paie la facture (vérification du 09.11.2026) : un acompte plus
        // grand que le total n'en paie que le total. Ce qui était déjà réglé avant ce règlement, puis
        // après, chacun borné entre 0 et le total ; la part de la TVA suit la différence
        $avant = round((float) $facture->settlements
            ->filter(fn (Settlement $x): bool => [$x->received_on->toDateString(), $x->id] < [$s->received_on->toDateString(), $s->id])
            ->sum(fn (Settlement $x): float => (float) $x->amount), 2);
        $borne = fn (float $v): float => min(max($v, 0.0), max($total, 0.0));
        $part = $total > 0 ? ($borne($avant + (float) $s->amount) - $borne($avant)) / $total : 0.0;
        $breakdown = array_map(function (array $taux) use ($part): array {
            $ttc = round($taux['incl_vat'] * $part, 2);
            $tva = round($taux['vat'] * $part, 2);

            return ['rate' => $taux['rate'], 'excl_vat' => round($ttc - $tva, 2), 'vat' => $tva, 'incl_vat' => $ttc];
        }, $facture->vatBreakdown());

        return [
            'id' => $s->id,
            'recu_le' => $s->received_on->toDateString(),
            'numero' => $facture->numero,
            'emise_le' => $facture->emise_le,
            'patient_name' => $facture->patient_name,
            'owner_name' => $facture->owner_name,
            'moyen' => $s->method->value,
            'montant' => round((float) $s->amount, 2),
            'montant_tva' => VatBreakdown::vat($breakdown),
            'vat_breakdown' => $breakdown,
            // Ce qui dépasse le total de la facture (un acompte plus grand) : ni chiffre d'affaires ni TVA
            'excedent' => round((float) $s->amount - $part * $total, 2),
            'reference' => $s->reference,
            'motif' => $s->reason,
            'annule' => $s->reverses_id,
            'saisi_par' => $s->recordedBy?->name,
        ];
    }

    /** @return array<string, mixed> */
    private function ligne(Facture $facture): array
    {
        return [
            'numero' => $facture->numero,
            'emise_le' => $facture->emise_le,
            'payee_le' => $facture->payee_le,
            'patient_name' => $facture->patient_name,
            'owner_name' => $facture->owner_name,
            'detail' => $facture->lignes->map(fn (FactureLigne $ligne): string => $ligne->libelle.' x'.$ligne->quantite)->implode(' | '),
            'total_ttc' => $facture->totalTtc(),
            'montant_tva' => $facture->montantTva(),
            'vat_breakdown' => $facture->vatBreakdown(),
            'moyen_paiement' => $facture->moyen_paiement,
            'statut' => $facture->statut,
            'payee_en_partie' => $facture->estPayeeEnPartie(),
        ];
    }
}
