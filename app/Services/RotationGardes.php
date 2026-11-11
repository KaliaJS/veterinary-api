<?php

namespace App\Services;

use App\Auth\ContexteClinique;
use App\Enums\StatutAbsence;
use App\Enums\TypeAbsence;
use App\Models\Absence;
use App\Models\Employe;
use App\Models\Garde;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * La garde de chaque nuit (docs/data-model.md §1). Une nuit enregistrée
 * (`gardes`) tient : chaque matin, la nuit passée s'enregistre telle que
 * l'équipe l'a vue (É27, `gardes:enregistrer`, le registre de l'OLT 1
 * art. 73), et un manager corrige une nuit des 30 derniers jours (É28).
 * Regarder n'écrit rien. Un choix tient tant que son vétérinaire est là
 * cette nuit-là (ni absent ni hors contrat ; pour une nuit à venir, avec
 * son accès) ; sinon, et sans choix, la nuit va au vétérinaire présent qui
 * a eu le moins de nuits sur les quatre semaines d'avant, puis à celui
 * dont la dernière nuit de ces quatre semaines est la plus ancienne ; le
 * dimanche au vétérinaire du samedi s'il est là (choix de Jim du
 * 11.11.2026, « Balanced suggestion » : rien n'est bloqué). Le calcul part
 * toujours de huit semaines avant aujourd'hui, avec les nuits du registre
 * des quatre semaines d'avant : une nuit à venir montre le même
 * vétérinaire quelle que soit la période demandée.
 * Le compteur suit l'OLT 2 (art. 8b) : 7 nuits au plus sur 4 semaines.
 */
class RotationGardes
{
    public const MAXIMUM_QUATRE_SEMAINES = 7;

    /** @var Collection<int, Employe>|null */
    private ?Collection $veterinaires = null;

    /** @var array<int, Employe> Les personnes des nuits, pour leur nom (un vétérinaire suspendu depuis compris) */
    private array $personnes = [];

    /** @var array{du: string, au: string, nuits: array<string, array{id: int|null, est_choisie: bool, est_enregistree: bool}>}|null */
    private ?array $plan = null;

    /** @return Collection<int, Employe> Les vétérinaires qui peuvent être de garde */
    public function veterinaires(): Collection
    {
        return $this->veterinaires ??= Employe::query()
            ->where('role_libelle', 'like', 'Vétérinaire%')
            ->where('acces_suspendu', false)
            ->orderBy('id')
            ->get()
            ->values();
    }

    /**
     * Une ligne par nuit, du premier au dernier jour.
     *
     * @return list<array{jour: string, employe_id: int|null, nom: string|null, initiales: string|null, est_choisie: bool}>
     */
    public function nuits(CarbonImmutable $du, CarbonImmutable $au): array
    {
        $plan = $this->plan($du, $au);

        $nuits = [];
        for ($jour = $du; $jour->lte($au); $jour = $jour->addDay()) {
            $nuit = $plan[$jour->toDateString()] ?? ['id' => null, 'est_choisie' => false];
            $employe = $nuit['id'] !== null ? ($this->personnes[$nuit['id']] ?? null) : null;
            $nuits[] = [
                'jour' => $jour->toDateString(),
                'employe_id' => $nuit['id'],
                'nom' => $employe?->nom,
                'initiales' => $employe?->initiales,
                'est_choisie' => $nuit['est_choisie'],
            ];
        }

        return $nuits;
    }

    /**
     * Pour chaque semaine (son lundi), les nuits de chaque vétérinaire
     * sur les quatre semaines qui finissent ce dimanche.
     *
     * @return list<array{lundi: string, nuits: list<array{employe_id: int, nuits: int}>}>
     */
    public function compteurs(CarbonImmutable $du, CarbonImmutable $au): array
    {
        $premierLundi = $du->startOfWeek(CarbonImmutable::MONDAY);
        $dernierDimanche = $au->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay();
        $parJour = collect($this->nuits($premierLundi->subWeeks(3), $dernierDimanche))->pluck('employe_id', 'jour');

        $compteurs = [];
        for ($lundi = $premierLundi; $lundi->lte($au); $lundi = $lundi->addWeek()) {
            $fenetre = $parJour->filter(fn (?int $id, string $jour): bool => $jour >= $lundi->subWeeks(3)->toDateString() && $jour <= $lundi->addDays(6)->toDateString());
            $compteurs[] = [
                'lundi' => $lundi->toDateString(),
                // Les vétérinaires d'aujourd'hui, et qui a fait des nuits de ces quatre semaines (parti depuis compris)
                'nuits' => collect($this->veterinaires()->pluck('id'))
                    ->merge($fenetre->filter()->values())
                    ->unique()
                    ->values()
                    ->map(fn (int $employeId): array => ['employe_id' => $employeId, 'nuits' => $fenetre->filter(fn (?int $id): bool => $id === $employeId)->count()])
                    ->all(),
            ];
        }

        return $compteurs;
    }

    /**
     * Les nuits jusqu'au dernier jour, calculées depuis huit semaines
     * avant aujourd'hui (une période plus ancienne à part, pour être
     * montrée), gardées pour la requête.
     *
     * @return array<string, array{id: int|null, est_choisie: bool, est_enregistree: bool}>
     */
    private function plan(CarbonImmutable $du, CarbonImmutable $au): array
    {
        $aujourdhui = aujourdhui()->toImmutable();
        // Le calcul part toujours de huit semaines avant aujourd'hui (une nuit à venir ne dépend pas de la période
        // demandée) ; une période plus ancienne se calcule à part, pour être montrée seulement
        $depart = $aujourdhui->subDays(54);
        $debut = $du->min($depart)->subDays(27);
        if ($this->plan !== null && $this->plan['du'] <= $debut->toDateString() && $this->plan['au'] >= $au->toDateString()) {
            return $this->plan['nuits'];
        }

        $veterinaires = $this->veterinaires();
        foreach ($veterinaires as $veterinaire) {
            $this->personnes[$veterinaire->id] = $veterinaire;
        }
        // Le nom d'une personne qui n'est plus de la clinique reste lisible sur ses nuits
        $stockees = Garde::query()
            ->with(['employe' => fn ($employe) => $employe->withoutGlobalScope('clinique')])
            ->whereDate('jour', '>=', $debut)
            ->whereDate('jour', '<=', $au)
            ->get()
            ->keyBy(fn (Garde $garde): string => $garde->jour->toDateString());
        foreach ($stockees as $garde) {
            if ($garde->employe !== null) {
                $this->personnes[$garde->employe_id] ??= $garde->employe;
            }
        }
        // Les absences de chacun, une personne partie depuis comprise
        $absents = $this->absents(array_keys($this->personnes), $debut, $au->addDay());
        // Là cette nuit-là : pas absent, sous contrat
        $estLa = function (int $id, CarbonImmutable $jour) use ($absents): bool {
            $employe = $this->personnes[$id] ?? null;

            return $employe !== null
                && ! in_array($id, $absents[$jour->toDateString()] ?? [], true)
                && ($employe->entree_le === null || $employe->entree_le->lte($jour))
                && ($employe->fin_contrat_le === null || $employe->fin_contrat_le->gte($jour));
        };
        $ids = $veterinaires->pluck('id')->all();
        // Une nuit du registre tient toujours ; un choix fait d'avance, tant que son vétérinaire est là et a son accès
        $tient = function (string $cle, CarbonImmutable $jour) use ($stockees, $ids, $estLa): ?Garde {
            $garde = $stockees->get($cle);
            if ($garde === null) {
                return null;
            }
            if ($garde->enregistree_le !== null || ! $garde->est_choisie) {
                return $garde;
            }

            // Pas encore au registre : son vétérinaire doit être là et avoir encore son accès, passée ou à venir
            return $estLa($garde->employe_id, $jour) && in_array($garde->employe_id, $ids, true) ? $garde : null;
        };

        $calculer = function (CarbonImmutable $de, CarbonImmutable $a, array $nuits) use ($tient, $estLa, $ids): array {
            for ($jour = $de; $jour->lte($a); $jour = $jour->addDay()) {
                $cle = $jour->toDateString();
                $garde = $tient($cle, $jour);
                if ($garde !== null) {
                    $nuits[$cle] = ['id' => $garde->employe_id, 'est_choisie' => $garde->est_choisie, 'est_enregistree' => $garde->enregistree_le !== null || ! $garde->est_choisie];

                    continue;
                }
                // Le dimanche au vétérinaire du samedi, s'il est là et a encore son accès
                $samedi = $nuits[$jour->subDay()->toDateString()]['id'] ?? null;
                if ($jour->isSunday() && $samedi !== null && $estLa($samedi, $jour) && in_array($samedi, $ids, true)) {
                    $nuits[$cle] = ['id' => $samedi, 'est_choisie' => false, 'est_enregistree' => false];

                    continue;
                }
                $presents = array_values(array_filter($ids, fn (int $id): bool => $estLa($id, $jour)));
                if ($jour->isSaturday()) {
                    // Le week-end d'un bloc : d'abord ceux qui sont là les deux nuits
                    $lesDeux = array_values(array_filter($presents, fn (int $id): bool => $estLa($id, $jour->addDay())));
                    $presents = $lesDeux !== [] ? $lesDeux : $presents;
                }
                $nuits[$cle] = ['id' => $this->leMoinsDeNuits($presents, $nuits, $jour), 'est_choisie' => false, 'est_enregistree' => false];
            }

            return $nuits;
        };

        // Les nuits enregistrées des quatre semaines avant le départ : l'histoire du calcul
        $histoire = [];
        for ($jour = $depart->subDays(27); $jour->lt($depart); $jour = $jour->addDay()) {
            $garde = $tient($jour->toDateString(), $jour);
            if ($garde !== null) {
                $histoire[$jour->toDateString()] = ['id' => $garde->employe_id, 'est_choisie' => $garde->est_choisie, 'est_enregistree' => $garde->enregistree_le !== null || ! $garde->est_choisie];
            }
        }
        $anciennes = $du->lt($depart) ? $calculer($du->subDays(27), $depart->subDay(), []) : [];
        $nuits = [...$anciennes, ...$calculer($depart, $au, $histoire)];

        $this->plan = ['du' => $debut->toDateString(), 'au' => $au->toDateString(), 'nuits' => $nuits];

        return $nuits;
    }

    /**
     * Le registre (É27) : les nuits passées du premier au dernier jour,
     * enregistrées telles qu'elles se montrent ; un choix fait d'avance
     * devient l'enregistrement de sa nuit (ou le nom de qui l'a remplacé,
     * si son vétérinaire était absent). Une nuit déjà au registre, ou
     * corrigée entre-temps, n'est pas touchée. Sans événement : rien ne
     * change à l'écran.
     */
    public function enregistrer(CarbonImmutable $du, CarbonImmutable $au): int
    {
        $cliniqueId = app(ContexteClinique::class)->cliniqueId();
        $au = $au->min(aujourdhui()->toImmutable()->subDay());
        if ($cliniqueId === null || $du->gt($au)) {
            return 0;
        }
        $plan = $this->plan($du, $au);
        $stockees = Garde::query()->whereDate('jour', '>=', $du)->whereDate('jour', '<=', $au)->get()->keyBy(fn (Garde $garde): string => $garde->jour->toDateString());
        $maintenant = now();
        $ecrites = 0;
        for ($jour = $du; $jour->lte($au); $jour = $jour->addDay()) {
            $cle = $jour->toDateString();
            $id = $plan[$cle]['id'] ?? null;
            $garde = $stockees->get($cle);
            if ($id === null || $garde?->enregistree_le !== null || ($garde !== null && ! $garde->est_choisie)) {
                continue;
            }
            if ($garde !== null) {
                // Seulement si personne ne l'a corrigée entre-temps
                $ecrites += Garde::query()->whereKey($garde->id)->whereNull('enregistree_le')->update([
                    'employe_id' => $id,
                    'est_choisie' => $garde->employe_id === $id,
                    'enregistree_le' => $maintenant,
                    'updated_at' => $maintenant,
                ]);
            } else {
                $ecrites += Garde::query()->insertOrIgnore(['clinique_id' => $cliniqueId, 'jour' => $cle, 'employe_id' => $id, 'est_choisie' => false, 'enregistree_le' => $maintenant, 'created_at' => $maintenant, 'updated_at' => $maintenant]);
            }
        }

        return $ecrites;
    }

    /**
     * Le vétérinaire qui a eu le moins de nuits sur les quatre semaines
     * d'avant, puis celui dont la dernière nuit de ces quatre semaines est
     * la plus ancienne (aucune d'abord), puis le plus ancien : rien qui
     * dépende de la période demandée.
     *
     * @param  list<int>  $candidats
     * @param  array<string, array{id: int|null, est_choisie: bool, est_enregistree: bool}>  $nuits
     */
    private function leMoinsDeNuits(array $candidats, array $nuits, CarbonImmutable $jour): ?int
    {
        if ($candidats === []) {
            return null;
        }
        // Les 27 nuits d'avant : avec celle-ci, quatre semaines ; la plus récente d'abord
        $compte = array_fill_keys($candidats, 0);
        $recul = array_fill_keys($candidats, 99);
        for ($n = 1; $n <= 27; $n++) {
            $id = $nuits[$jour->subDays($n)->toDateString()]['id'] ?? null;
            if ($id !== null && isset($compte[$id])) {
                $compte[$id]++;
                $recul[$id] = min($recul[$id], $n);
            }
        }
        usort($candidats, fn (int $a, int $b): int => [$compte[$a], -$recul[$a], $a] <=> [$compte[$b], -$recul[$b], $b]);

        return $candidats[0];
    }

    /**
     * Les personnes absentes chaque jour (absence validée qui bloque).
     *
     * @param  list<int>  $ids
     * @return array<string, list<int>>
     */
    private function absents(array $ids, CarbonImmutable $du, CarbonImmutable $au): array
    {
        $parJour = [];
        Absence::query()
            ->withoutGlobalScope('clinique')
            ->whereIn('employe_id', $ids)
            ->where('statut', StatutAbsence::Validee)
            ->where('type', '!=', TypeAbsence::Garde)
            ->whereDate('debut_le', '<=', $au)
            ->whereDate('fin_le', '>=', $du)
            ->get()
            ->each(function (Absence $absence) use (&$parJour, $du, $au): void {
                $debut = $absence->debut_le->toImmutable()->max($du);
                $fin = $absence->fin_le->toImmutable()->min($au);
                for ($jour = $debut; $jour->lte($fin); $jour = $jour->addDay()) {
                    $parJour[$jour->toDateString()][] = $absence->employe_id;
                }
            });

        return $parJour;
    }
}
