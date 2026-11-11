<?php

namespace App\Services\Equipe;

use App\Enums\StatutAbsence;
use App\Enums\TypeAbsence;
use App\Models\Absence;
use App\Models\Employe;
use App\Models\Organisation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * La cloche de l'Équipe (Jim, 11.11.2026, É5 et É11), pour la clinique
 * du jeton : les demandes à valider ; les jours reportés de l'an passé
 * au-dessus du seuil de l'organisation et pas encore planifiés, à poser
 * avant sa date ; et dès le 1er septembre, qui n'a pas encore deux
 * semaines de suite cette année (CO 329c), si le rappel est gardé.
 */
class CongesATraiter
{
    public function __construct(private SoldesConges $soldes) {}

    /** @return array{a_valider: list<array<string, mixed>>, a_planifier: list<array<string, mixed>>, deux_semaines: list<array<string, mixed>>} */
    public function pour(Organisation $organisation): array
    {
        $aujourdhui = aujourdhui()->toImmutable();
        $employes = Employe::query()
            ->with(['congesValides', 'cliniques'])
            ->where('acces_suspendu', false)
            ->where(fn ($requete) => $requete->whereNull('fin_contrat_le')->orWhereDate('fin_contrat_le', '>=', $aujourdhui))
            ->orderBy('nom')
            ->get();

        return [
            'a_valider' => $this->aValider(),
            'a_planifier' => $this->aPlanifier($employes, $organisation, $aujourdhui),
            'deux_semaines' => $this->deuxSemaines($employes, $organisation, $aujourdhui),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function aValider(): array
    {
        return Absence::query()
            ->with(['employe.congesValides', 'employe.cliniques'])
            ->where('statut', StatutAbsence::EnAttente)
            ->orderBy('debut_le')
            ->orderBy('id')
            ->get()
            ->map(fn (Absence $absence): array => [
                'id' => $absence->id,
                'employe_id' => $absence->employe_id,
                'nom' => $absence->employe->nom,
                'initiales' => $absence->employe->initiales,
                'type' => $absence->type,
                'debut_le' => $absence->debut_le->toDateString(),
                'fin_le' => $absence->fin_le->toDateString(),
                'jours' => SoldesConges::joursOuvres($absence->debut_le, $absence->fin_le, SoldesConges::cliniqueDe($absence->employe)),
                'note' => $absence->note,
                // Le solde de l'année de la demande (null : une année pas encore comptée)
                'solde' => $absence->type === TypeAbsence::Conge ? $this->soldeDeLAnnee($absence->employe, $absence->debut_le->year) : null,
            ])
            ->values()
            ->all();
    }

    private function soldeDeLAnnee(Employe $employe, int $annee): int|float|null
    {
        foreach ($this->soldes->annees($employe) as $ligne) {
            if ($ligne['annee'] === $annee) {
                return $ligne['solde'];
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, Employe>  $employes
     * @return list<array<string, mixed>>
     */
    private function aPlanifier(Collection $employes, Organisation $organisation, CarbonImmutable $aujourdhui): array
    {
        $avantLe = CarbonImmutable::parse($aujourdhui->year.'-'.$organisation->conges_planifier_avant);

        return $employes
            ->map(function (Employe $employe) use ($organisation, $avantLe, $aujourdhui): ?array {
                $annee = $this->soldes->anneeEnCours($employe);
                // Les jours posés cette année entament d'abord ceux de l'an passé
                $aPlanifier = max(0, $annee['report'] - $annee['pris'] - $annee['planifies']);
                if ($annee['annee'] !== $aujourdhui->year || $aPlanifier <= $organisation->conges_seuil_report) {
                    return null;
                }

                return [
                    'employe_id' => $employe->id,
                    'nom' => $employe->nom,
                    'initiales' => $employe->initiales,
                    'reportes' => $annee['report'],
                    'a_planifier' => SoldesConges::nombre((float) $aPlanifier),
                    'avant_le' => $avantLe->toDateString(),
                    'est_depasse' => $aujourdhui->gt($avantLe),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Employe>  $employes
     * @return list<array<string, mixed>>
     */
    private function deuxSemaines(Collection $employes, Organisation $organisation, CarbonImmutable $aujourdhui): array
    {
        if (! $organisation->conges_rappel_deux_semaines || $aujourdhui->month < 9) {
            return [];
        }

        return $employes
            ->filter(fn (Employe $employe): bool => $this->soldes->droit($employe, $aujourdhui->year) >= 10)
            ->map(function (Employe $employe) use ($aujourdhui): ?array {
                $plusLong = $this->plusLongueSuite($employe, $aujourdhui->year);

                return $plusLong >= 10 ? null : [
                    'employe_id' => $employe->id,
                    'nom' => $employe->nom,
                    'initiales' => $employe->initiales,
                    'plus_long' => $plusLong,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Les jours de la semaine (lundi à vendredi) de la plus longue suite de
     * congés de l'année : deux semaines de suite, jours fériés compris ; un
     * week-end ou un jour férié entre deux congés ne la coupe pas.
     */
    private function plusLongueSuite(Employe $employe, int $annee): int|float
    {
        $clinique = SoldesConges::cliniqueDe($employe);
        $plusLong = 0;
        $suite = 0;
        $finPrecedente = null;
        foreach ($employe->congesValides as $absence) {
            $debut = $absence->debut_le->toImmutable()->max(CarbonImmutable::create($annee, 1, 1));
            $fin = $absence->fin_le->toImmutable()->min(CarbonImmutable::create($annee, 12, 31));
            if ($debut->gt($fin)) {
                continue;
            }
            $jours = SoldesConges::joursOuvres($debut, $fin);
            $estALaSuite = $finPrecedente !== null && SoldesConges::joursOuvres($finPrecedente->addDay(), $debut->subDay(), $clinique) == 0;
            $suite = $estALaSuite ? $suite + SoldesConges::joursOuvres($debut->max($finPrecedente->addDay()), $fin) : $jours;
            $finPrecedente = $finPrecedente === null ? $fin : $fin->max($finPrecedente);
            $plusLong = max($plusLong, $suite);
        }

        return $plusLong;
    }
}
