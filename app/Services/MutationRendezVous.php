<?php

namespace App\Services;

use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Models\Absence;
use App\Models\LignePlanning;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\Salle;
use App\Models\User;
use App\Services\Paiements\AcomptesRendezVous;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Toute mutation de rendez-vous passe ici : application des attributs
 * puis trace d'audit immuable — une ligne par champ modifié, un
 * `batch_id` ULID par geste (un déplacement = startsAt + laneId dans le
 * même lot). Les noms de champ audités sont les codes camelCase du
 * front (docs/data-model.md §3), les datetimes sont sérialisées en
 * ISO 8601 UTC, la salle par son nom au moment du geste.
 */
class MutationRendezVous
{
    /** Attribut modèle → champ d'audit (code du front). */
    private const array CHAMPS_AUDITES = [
        'starts_at' => 'startsAt',
        'ends_at' => 'endsAt',
        'ligne_planning_id' => 'laneId',
        'salle_id' => 'room',
        'status' => 'status',
        'arrived_at' => 'arrivedAt',
        'patient_name' => 'patientName',
        'species' => 'species',
        'owner_name' => 'ownerName',
        'type' => 'type',
        'reason' => 'reason',
        'note_accueil' => 'noteAccueil',
    ];

    /**
     * Prépare les attributs d'une création : bornes datetime depuis la
     * durée, dénormalisations réalignées sur la fiche patient quand
     * elle est référencée (la FK fait foi).
     *
     * @param  array<string, mixed>  $donnees
     * @return array<string, mixed>
     */
    public function preparerCreation(array $donnees): array
    {
        $debut = CarbonImmutable::parse($donnees['starts_at']);

        $attributs = [
            'ligne_planning_id' => $donnees['ligne_planning_id'],
            'salle_id' => $donnees['salle_id'] ?? null,
            'patient_id' => $donnees['patient_id'] ?? null,
            'client_id' => null,
            'patient_name' => $donnees['patient_name'] ?? null,
            'species' => $donnees['species'] ?? null,
            'owner_name' => $donnees['owner_name'] ?? null,
            'type' => $donnees['type'] ?? TypeRendezVous::Consultation,
            'status' => $donnees['status'] ?? StatutRendezVous::Prevu,
            'flags' => $donnees['flags'] ?? [],
            'reason' => $donnees['reason'] ?? null,
            'note_accueil' => $donnees['note_accueil'] ?? null,
            'arrived_at' => $donnees['arrived_at'] ?? null,
            'starts_at' => $debut,
            'ends_at' => $debut->addMinutes((int) $donnees['duree_minutes']),
        ];

        if ($attributs['patient_id'] !== null) {
            $attributs = [...$attributs, ...Patient::findOrFail($attributs['patient_id'])->denormalisation()];
        }

        return $attributs;
    }

    /** @param array<string, mixed> $attributs */
    public function creer(array $attributs, ?User $auteur): RendezVous
    {
        return DB::transaction(function () use ($attributs, $auteur): RendezVous {
            $rendezVous = RendezVous::create($attributs);

            $this->tracer($rendezVous, $auteur, ['création' => [null, [
                'laneId' => $rendezVous->ligne_planning_id,
                'startsAt' => $rendezVous->starts_at->toISOString(),
                'endsAt' => $rendezVous->ends_at->toISOString(),
            ]]]);

            return $rendezVous;
        });
    }

    /**
     * Applique un patch et audite chaque champ modifié sous un batch
     * unique. Retourne le batch_id, ou null si rien n'a changé.
     *
     * @param  array<string, mixed>  $attributs
     * @param  list<string>  $champsNonAudites  attributs modifiés sans entrée d'audit (parité front : un déplacement n'audite pas le `endsAt` qui suit le début)
     * @param  array<string, array{mixed, mixed}>  $entreesSupplementaires  entrées hors attributs (ex. motifAnnulation)
     */
    public function appliquer(RendezVous $rendezVous, array $attributs, ?User $auteur, array $champsNonAudites = [], array $entreesSupplementaires = []): ?string
    {
        $rendezVous->fill($attributs);

        $changements = [];
        foreach (array_keys($rendezVous->getDirty()) as $attribut) {
            $champ = self::CHAMPS_AUDITES[$attribut] ?? null;

            if ($champ === null || in_array($attribut, $champsNonAudites, true)) {
                continue;
            }

            $changements[$champ] = [
                $this->serialiser($attribut, $rendezVous->getOriginal($attribut)),
                $this->serialiser($attribut, $rendezVous->getAttribute($attribut)),
            ];
        }

        $changements = [...$changements, ...$entreesSupplementaires];

        if (! $rendezVous->isDirty() && $changements === []) {
            return null;
        }

        return DB::transaction(function () use ($rendezVous, $auteur, $changements): string {
            $rendezVous->save();

            return $this->tracer($rendezVous, $auteur, $changements);
        });
    }

    /**
     * Absence bloquante validée → les colonnes du praticien sont vidées
     * sur la période, dans chacune des cliniques où il consulte (la fiche
     * et ses absences sont celles de la personne) : statut `annule`
     * audité avec motif, jamais de suppression. Un batch d'audit par
     * rendez-vous. Rend le nombre de rendez-vous (hors tampons) annulés, et
     * ceux restés au planning, à annuler à la main, parce que leur acompte
     * n'a pas pu être rendu ou qu'une erreur a empêché leur annulation
     * (24.10.2026).
     *
     * @return array{annules: int, restants: int}
     */
    public function annulerPourAbsence(Absence $absence, ?User $auteur): array
    {
        if (! $absence->estBloquante()) {
            return ['annules' => 0, 'restants' => 0];
        }

        $motif = 'Absence praticien : '.mb_strtolower($absence->type->libelle());

        $lignes = LignePlanning::withoutGlobalScope('clinique')
            ->with('clinique')
            ->where('employe_id', $absence->employe_id)
            ->get();

        // Les jours d'absence sont des jours calendaires de chaque
        // clinique : les bornes se posent à minuit local, puis se
        // comparent aux datetimes UTC des rendez-vous.
        $rendezVous = $lignes->flatMap(function (LignePlanning $ligne) use ($absence) {
            $fuseau = $ligne->clinique->fuseau_horaire ?? 'Europe/Zurich';
            $debut = CarbonImmutable::parse($absence->debut_le->toDateString(), $fuseau);
            $fin = CarbonImmutable::parse($absence->fin_le->copy()->addDay()->toDateString(), $fuseau);

            // Seulement ce qui est encore à venir et prévu (24.10.2026) : un
            // rendez-vous commencé, fini ou noté absent n'est jamais annulé
            return RendezVous::withoutGlobalScope('clinique')
                ->where('ligne_planning_id', $ligne->id)
                ->where('status', StatutRendezVous::Prevu)
                ->where('starts_at', '>', now())
                ->where('starts_at', '<', $fin->utc())
                ->where('ends_at', '>', $debut->utc())
                ->orderBy('starts_at')
                ->orderBy('id')
                ->get();
        });
        // Dans l'ordre de leur heure, toutes colonnes confondues (24.10.2026)
        $rendezVous = $rendezVous->sortBy([['starts_at', 'asc'], ['id', 'asc']])->values();

        $annules = 0;
        $restants = 0;

        foreach ($rendezVous as $unRendezVous) {
            // L'acompte payé en ligne est rendu en entier, d'abord (24.10.2026) ;
            // si le prestataire refuse, le rendez-vous reste au planning, à
            // annuler à la main (l'annulation réessaie le rendu)
            // Rendu et annulation ensemble ou pas du tout (24.10.2026) : une
            // décision d'acompte n'est jamais notée pour un rendez-vous resté prévu
            try {
                DB::transaction(function () use ($unRendezVous, $auteur, $motif): void {
                    app(AcomptesRendezVous::class)->surAbsenceDuPraticien($unRendezVous);
                    $this->appliquer(
                        $unRendezVous,
                        ['status' => StatutRendezVous::Annule],
                        $auteur,
                        entreesSupplementaires: ['motifAnnulation' => [null, $motif]],
                    );
                });
            } catch (Throwable $e) {
                // Prestataire qui refuse, ou toute autre erreur : ce rendez-vous
                // reste au planning, compté à annuler à la main ; les suivants
                // sont traités et l'absence est enregistrée (24.10.2026)
                report($e);
                if (! $unRendezVous->estTampon()) {
                    $restants++;
                }

                continue;
            }

            if (! $unRendezVous->estTampon()) {
                $annules++;
            }
        }

        return ['annules' => $annules, 'restants' => $restants];
    }

    /** @param array<string, array{mixed, mixed}> $changements */
    public function tracer(RendezVous $rendezVous, ?User $auteur, array $changements): string
    {
        $batch = (string) Str::ulid();
        $maintenant = now();

        foreach ($changements as $champ => [$ancienne, $nouvelle]) {
            $rendezVous->audits()->create([
                'user_id' => $auteur?->id,
                'batch_id' => $batch,
                'champ' => $champ,
                'ancienne_valeur' => $ancienne,
                'nouvelle_valeur' => $nouvelle,
                'created_at' => $maintenant,
            ]);
        }

        return $batch;
    }

    private function serialiser(string $attribut, mixed $valeur): mixed
    {
        if ($valeur === null) {
            return null;
        }

        if ($attribut === 'salle_id') {
            return Salle::find($valeur)?->nom;
        }

        if ($valeur instanceof CarbonInterface) {
            return $valeur->toISOString();
        }

        if ($valeur instanceof BackedEnum) {
            return $valeur->value;
        }

        return $valeur;
    }
}
