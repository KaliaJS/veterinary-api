<?php

namespace App\Services\Facturation;

use App\Enums\SettlementMethod;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Models\Clinique;
use App\Models\Facture;
use App\Models\Settlement;
use App\Models\Societe;
use App\Support\PlanComptable;
use Carbon\CarbonImmutable;

/**
 * Les écritures comptables d'un mois d'une société (point e, 09.11.2026),
 * en partie double, dans l'ordre de Banana (Date, Doc, Description,
 * AccountDebit, AccountCredit, Amount, VatCode). Une facture émise : le
 * compte clients au débit, les produits au crédit, TTC avec le code TVA
 * de chaque taux ; découpée quand elle a plusieurs comptes ou taux (une
 * ligne du total au débit, puis une ligne par compte et taux au crédit).
 * Un règlement reçu : le compte de son moyen au débit, les clients au
 * crédit ; négatif, en sens inverse. Le web en fait le fichier Banana et
 * le CSV ; rien n'est recalculé de son côté.
 */
class AccountingEntries
{
    /**
     * @return list<array{date: string, doc: string, description: string, debit: string, credit: string, amount: float, vat_code: string}>
     */
    public function month(Societe $societe, string $month): array
    {
        $cliniques = Clinique::query()->withoutGlobalScopes()->where('societe_id', $societe->id)->get(['id', 'fuseau_horaire']);
        $ids = $cliniques->pluck('id')->all();
        $zone = $cliniques->first()?->fuseau_horaire ?: 'Europe/Zurich';
        $debut = CarbonImmutable::parse($month.'-01', $zone);
        $fin = $debut->addMonth();
        [$du, $au] = [$debut->utc(), $fin->utc()];
        $plan = PlanComptable::pour($societe);
        $assujettie = $societe->assujettie_tva !== false;
        $jour = fn ($instant): string => CarbonImmutable::parse($instant)->setTimezone($zone)->toDateString();
        $rows = [];

        // Les factures émises dans le mois ; une vente du comptoir émise et annulée dans le mois n'a jamais rien dû
        $emises = Facture::query()->withoutGlobalScopes()->whereIn('clinique_id', $ids)
            ->where('emise_le', '>=', $du)->where('emise_le', '<', $au)
            ->with('lignes')->orderBy('emise_le')->orderBy('id')->get();
        foreach ($emises as $facture) {
            if ($facture->statut === StatutFacture::Annulee && $facture->annulee_le !== null && $facture->annulee_le->lt($au)) {
                continue;
            }
            array_push($rows, ...$this->invoice($facture, $jour($facture->emise_le), $plan, $assujettie, false));
        }

        // Annulée ce mois, émise avant : l'écriture inverse, au jour de l'annulation
        $annulees = Facture::query()->withoutGlobalScopes()->whereIn('clinique_id', $ids)
            ->where('statut', StatutFacture::Annulee)
            ->where('annulee_le', '>=', $du)->where('annulee_le', '<', $au)
            ->where('emise_le', '<', $du)
            ->with('lignes')->orderBy('annulee_le')->orderBy('id')->get();
        foreach ($annulees as $facture) {
            array_push($rows, ...$this->invoice($facture, $jour($facture->annulee_le), $plan, $assujettie, true));
        }

        // Les règlements reçus dans le mois
        $reglements = Settlement::query()->withoutGlobalScopes()->whereIn('clinique_id', $ids)
            ->whereBetween('received_on', [$debut->toDateString(), $fin->subDay()->toDateString()])
            ->with('facture')->orderBy('received_on')->orderBy('id')->get();
        foreach ($reglements as $settlement) {
            $rows[] = $this->settlement($settlement, $plan);
        }

        usort($rows, fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        return $rows;
    }

    /**
     * Une facture : le total au débit du compte clients, les produits au
     * crédit, par compte et par taux. Inverse : les deux sens échangés.
     *
     * @param  array{comptes: array<string, string>, codes_tva: array<string, string>}  $plan
     * @return list<array{date: string, doc: string, description: string, debit: string, credit: string, amount: float, vat_code: string}>
     */
    private function invoice(Facture $facture, string $date, array $plan, bool $assujettie, bool $inverse): array
    {
        $total = $facture->totalTtc();
        if (abs($total) < 0.005) {
            return [];
        }

        // Par compte (prestations, marchandises) et par taux ; une remise sans taux à part
        $groupes = [];
        $remise = 0.0;
        foreach ($facture->lignes as $ligne) {
            $montant = $ligne->quantite * (float) $ligne->prix_chf;
            $taux = $ligne->vatRate($facture);
            if ($taux === null) {
                $remise += $montant;

                continue;
            }
            // Hors TVA : un seul groupe par compte, sans code
            $tauxGroupe = $assujettie ? $taux : null;
            $compte = $plan['comptes'][$ligne->type === TypeLigneFacture::Acte ? 'prestations' : 'marchandises'];
            $cle = $compte.'|'.($tauxGroupe ?? '-');
            $groupes[$cle] ??= ['compte' => $compte, 'taux' => $tauxGroupe, 'montant' => 0.0];
            $groupes[$cle]['montant'] += $montant;
        }
        if ($groupes === []) {
            $groupes['remise'] = ['compte' => $plan['comptes']['prestations'], 'taux' => null, 'montant' => 0.0];
        }

        // La remise se répartit au prorata des groupes ; le dernier prend l'arrondi : la somme fait le total
        $base = array_sum(array_map(fn (array $g): float => max(0.0, $g['montant']), $groupes));
        $groupes = array_values($groupes);
        $reste = round($total, 2);
        foreach ($groupes as $i => &$groupe) {
            $part = $base > 0 ? $remise * max(0.0, $groupe['montant']) / $base : ($i === 0 ? $remise : 0.0);
            $groupe['montant'] = $i === count($groupes) - 1 ? $reste : round($groupe['montant'] + $part, 2);
            $reste = round($reste - $groupe['montant'], 2);
        }
        unset($groupe);

        $description = ($inverse ? 'Annulation vente ' : 'Facture ').$facture->numero.$this->qui($facture);
        $clients = $plan['comptes']['clients'];
        $ligne = fn (string $debit, string $credit, float $montant, string $code): array => [
            'date' => $date, 'doc' => (string) $facture->numero, 'description' => $description,
            'debit' => $inverse ? $credit : $debit, 'credit' => $inverse ? $debit : $credit,
            'amount' => round($montant, 2), 'vat_code' => $code,
        ];

        if (count($groupes) === 1) {
            return [$ligne($clients, $groupes[0]['compte'], $total, PlanComptable::codeTva($plan, $groupes[0]['taux']))];
        }

        // Une ligne à 0 franc (un acte offert) n'apporte rien à la comptabilité
        $groupes = array_values(array_filter($groupes, fn (array $g): bool => abs($g['montant']) >= 0.005));
        if (count($groupes) === 1) {
            return [$ligne($clients, $groupes[0]['compte'], $total, PlanComptable::codeTva($plan, $groupes[0]['taux']))];
        }

        return [
            $ligne($clients, '', $total, ''),
            ...array_map(fn (array $g): array => $ligne('', $g['compte'], $g['montant'], PlanComptable::codeTva($plan, $g['taux'])), $groupes),
        ];
    }

    /**
     * Un règlement : le compte de son moyen au débit, les clients au crédit ;
     * négatif (annulation, remboursement), en sens inverse.
     *
     * @param  array{comptes: array<string, string>, codes_tva: array<string, string>}  $plan
     * @return array{date: string, doc: string, description: string, debit: string, credit: string, amount: float, vat_code: string}
     */
    private function settlement(Settlement $settlement, array $plan): array
    {
        $compte = $plan['comptes'][match ($settlement->method) {
            SettlementMethod::Especes => 'caisse',
            SettlementMethod::Virement => 'banque',
            default => 'transfert',
        }];
        $clients = $plan['comptes']['clients'];
        $montant = round((float) $settlement->amount, 2);
        $facture = $settlement->facture;
        $description = $montant >= 0
            ? 'Règlement '.$facture->numero.', '.mb_strtolower($settlement->method->label()).($settlement->reference ? ', réf. '.$settlement->reference : '')
            : ($settlement->reason ?: 'Remboursement').', '.$facture->numero;

        return [
            'date' => $settlement->received_on->toDateString(),
            'doc' => (string) $facture->numero,
            'description' => $description,
            'debit' => $montant >= 0 ? $compte : $clients,
            'credit' => $montant >= 0 ? $clients : $compte,
            'amount' => abs($montant),
            'vat_code' => '',
        ];
    }

    private function qui(Facture $facture): string
    {
        $qui = array_filter([$facture->patient_name, $facture->owner_name]);

        return $qui === [] ? '' : ', '.implode(', ', $qui);
    }
}
