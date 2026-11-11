<?php

namespace App\Services\Equipe;

use App\Models\Absence;
use App\Models\Clinique;
use App\Models\Employe;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Les congés année par année (docs/data-model.md §1), DÉRIVÉS, jamais
 * stockés : les jours ouvrés (du lundi au vendredi) des congés validés,
 * pris avant aujourd'hui ou planifiés ensuite ; le droit annuel de la
 * fiche, au prorata l'année d'entrée et celle de la fin du contrat ; le
 * reste de chaque année reporté sur la suivante, toujours (CO 329d al. 2
 * et 341, choix de Jim du 11.11.2026). Les jours pris ne se saisissent
 * pas : la première année comptée est celle de l'arrivée dans Veterinary
 * (ou de l'entrée, si plus tard), les congés déjà pris cette année-là se
 * posent comme absences ; seul le report d'avant se pose une fois
 * (`conges_report_arrivee`, É22).
 */
class SoldesConges
{
    /**
     * Toutes les années, la plus récente d'abord.
     *
     * @return list<array{annee: int, droit: int|float, report: int|float, pris: int|float, planifies: int|float, solde: int|float}>
     */
    public function annees(Employe $employe): array
    {
        $aujourdhui = aujourdhui()->toImmutable();
        $conges = $employe->congesValides;
        $clinique = self::cliniqueDe($employe);
        $premiere = max((int) ($employe->created_at ?? $aujourdhui)->year, (int) ($employe->entree_le->year ?? 0));
        $derniere = max($aujourdhui->year, (int) ($conges->max(fn (Absence $absence): int => $absence->fin_le->year) ?? 0));
        // Le contrat finit cette année-là : les années s'arrêtent à elle, même l'an prochain (son solde se prend ou se paie)
        if ($employe->fin_contrat_le !== null) {
            $derniere = $employe->fin_contrat_le->year;
        }
        $derniere = max($derniere, $premiere);

        $lignes = [];
        // Les jours d'avant Veterinary, posés une fois (É22)
        $report = (float) $employe->conges_report_arrivee;
        for ($annee = $premiere; $annee <= $derniere; $annee++) {
            $droit = $this->droit($employe, $annee);
            $pris = 0.0;
            $planifies = 0.0;
            foreach ($conges as $absence) {
                [$avant, $apres] = $this->joursDansLAnnee($absence, $annee, $aujourdhui, $clinique);
                $pris += $avant;
                $planifies += $apres;
            }
            $solde = $report + $droit - $pris - $planifies;
            $lignes[] = [
                'annee' => $annee,
                'droit' => self::nombre($droit),
                'report' => self::nombre($report),
                'pris' => self::nombre($pris),
                'planifies' => self::nombre($planifies),
                'solde' => self::nombre($solde),
            ];
            $report = $solde;
        }

        return array_reverse($lignes);
    }

    /**
     * L'année en cours (la dernière comptée, si le contrat a pris fin avant).
     *
     * @return array{annee: int, droit: int|float, report: int|float, pris: int|float, planifies: int|float, solde: int|float}
     */
    public function anneeEnCours(Employe $employe): array
    {
        $annees = $this->annees($employe);
        $cetteAnnee = aujourdhui()->year;

        foreach ($annees as $ligne) {
            if ($ligne['annee'] === $cetteAnnee) {
                return $ligne;
            }
        }

        return $annees[0];
    }

    /**
     * Du lundi au vendredi, bornes comprises, sans les jours fériés de la
     * clinique (É23 : un jour à moitié férié compte une demi-journée).
     */
    public static function joursOuvres(CarbonInterface $du, CarbonInterface $au, ?Clinique $clinique = null): int|float
    {
        $jours = 0.0;
        for ($jour = CarbonImmutable::parse($du->toDateString()); $jour->lte($au); $jour = $jour->addDay()) {
            if (! $jour->isWeekend()) {
                $jours += 1 - ($clinique?->joursFeries($jour->year)[$jour->toDateString()]['part'] ?? 0);
            }
        }

        return self::nombre($jours);
    }

    /** La clinique dont les jours fériés comptent : la plus ancienne de ses affectations (une personne dans deux cantons). */
    public static function cliniqueDe(Employe $employe): ?Clinique
    {
        return $employe->relationLoaded('cliniques')
            ? $employe->cliniques->sortBy('id')->first()
            : $employe->cliniques()->orderBy('cliniques.id')->first();
    }

    /** Le droit de l'année, au prorata des jours sous contrat, à la demi-journée. */
    public function droit(Employe $employe, int $annee): float
    {
        $debut = CarbonImmutable::create($annee, 1, 1);
        $fin = CarbonImmutable::create($annee, 12, 31);
        $sousContratDu = $employe->entree_le !== null && $employe->entree_le->year === $annee ? $employe->entree_le->toImmutable() : $debut;
        $sousContratAu = $employe->fin_contrat_le !== null && $employe->fin_contrat_le->year === $annee ? $employe->fin_contrat_le->toImmutable() : $fin;
        if ($sousContratDu->gt($sousContratAu)) {
            return 0.0;
        }
        $jours = $sousContratDu->diffInDays($sousContratAu) + 1;
        $total = $debut->diffInDays($fin) + 1;

        return round($employe->conges_droit * $jours / $total * 2) / 2;
    }

    /**
     * Les jours ouvrés d'un congé dans une année : avant aujourd'hui (pris), puis dès aujourd'hui (planifiés).
     *
     * @return array{int|float, int|float}
     */
    private function joursDansLAnnee(Absence $absence, int $annee, CarbonImmutable $aujourdhui, ?Clinique $clinique): array
    {
        $debut = $absence->debut_le->toImmutable()->max(CarbonImmutable::create($annee, 1, 1));
        $fin = $absence->fin_le->toImmutable()->min(CarbonImmutable::create($annee, 12, 31));
        if ($debut->gt($fin)) {
            return [0, 0];
        }
        $hier = $aujourdhui->subDay();
        $avant = $debut->lte($hier) ? self::joursOuvres($debut, $fin->min($hier), $clinique) : 0;
        $apres = $fin->gte($aujourdhui) ? self::joursOuvres($debut->max($aujourdhui), $fin, $clinique) : 0;

        return [$avant, $apres];
    }

    /** Un entier quand le nombre est rond (« 25 », pas « 25.0 »). */
    public static function nombre(float $valeur): int|float
    {
        return floor($valeur) === $valeur ? (int) $valeur : $valeur;
    }
}
