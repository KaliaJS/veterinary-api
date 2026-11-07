<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\StatutAnalyse;
use App\Enums\StatutOrdonnance;
use App\Enums\StatutRendezVous;
use App\Http\Controllers\Controller;
use App\Models\Analyse;
use App\Models\Clinique;
use App\Models\Document;
use App\Models\ExamenImagerie;
use App\Models\Ordonnance;
use App\Models\OrdonnanceLigne;
use App\Models\Patient;
use App\Models\Pesee;
use App\Models\Produit;
use App\Models\ReleveOphtalmologique;
use App\Models\RendezVous;
use App\Models\Vaccination;
use App\Models\Visite;
use App\Services\EtatVaccinal;
use App\Support\TreatmentDuration;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Le dossier d'un patient sur un fil (proposition B, choix de Jim,
 * 03.11.2026) : ses visites (rendez-vous passés et anciennes visites), ses
 * vaccins, ses pesées, ses ordonnances, ses examens (imagerie, analyses,
 * relevés ophtalmologiques) et ses documents, de toutes les cliniques de
 * l'organisation ; ce qui vient en tête (rendez-vous à venir, rappels de
 * vaccin, les échus d'abord), puis du plus récent au plus ancien, par pages
 * de trente. Même curseur que l'historique d'un propriétaire : `avant`
 * (inclusif, le `meta.suivant` reçu) et `sauter` (ceux de cette seconde
 * déjà servis) ; `type` n'en garde qu'une sorte (les filtres du dossier).
 * Les onglets en liste du dossier (07.11.2026) : `filtre` (selon la sorte)
 * et `q` (insensible à la casse et aux accents) portent sur toute
 * l'histoire de l'animal, avant la page et le curseur.
 */
class PatientTimelineController extends Controller
{
    private const PAGE_SIZE = 30;

    public const TYPES = ['visite', 'vaccin', 'poids', 'prescription', 'examen', 'document'];

    /** Les filtres des onglets, par sorte (les menus du dossier) */
    public const array FILTERS = [
        'vaccin' => ['due', 'current', 'renewed'],
        'poids' => ['trois', 'an'],
        'prescription' => ['active', 'renew', 'closed'],
        'examen' => ['imaging', 'lab', 'eye', 'out'],
    ];

    /** Le plus long texte cherché */
    public const int SEARCH_MAX = 100;

    /**
     * Filtré ou cherché, chaque source est lue en entier jusqu'à ce plafond
     * (les plus récentes d'abord) : une histoire d'animal n'en approche pas,
     * une base abîmée ne se lit pas sans fin (vérification du 07.11.2026).
     */
    public const int NARROWED_LIMIT = 2000;

    /** Le plafond, abaissé par un test seulement */
    public static ?int $narrowedLimitForTests = null;

    private const array MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    public function __invoke(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);
        $request->validate([
            'avant' => ['sometimes', 'nullable', 'date'],
            'sauter' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'type' => ['sometimes', 'nullable', Rule::in(self::TYPES)],
            // Un filtre n'existe que pour sa sorte
            'filtre' => ['sometimes', 'nullable', 'string', Rule::in(self::FILTERS[(string) $request->string('type')] ?? [])],
            // Une recherche porte sur une sorte : sans elle, toutes les sources de l'animal seraient lues
            'q' => ['sometimes', 'nullable', 'string', 'max:'.self::SEARCH_MAX, Rule::prohibitedIf(! $request->filled('type'))],
        ]);
        $before = $request->filled('avant') ? CarbonImmutable::parse((string) $request->string('avant'))->utc() : null;
        $skip = $before === null ? 0 : $request->integer('sauter');
        $type = $request->filled('type') ? (string) $request->string('type') : null;
        $filter = $request->filled('filtre') ? (string) $request->string('filtre') : null;
        $search = $this->normalized(trim((string) $request->string('q')));
        $search = $search === '' ? null : $search;
        // Un filtre ne garde qu'une source : les autres ne sont pas lues
        $sources = match ($filter) {
            'due' => ['rappel'],
            'current', 'renewed' => ['vaccination'],
            'imaging' => ['imagerie'],
            'lab', 'out' => ['analyse'],
            'eye' => ['releve_oph'],
            default => null,
        };
        $wants = fn (string $t, ?string $source = null): bool => ($type === null || $type === $t) && ($sources === null || $source === null || in_array($source, $sources, true));
        $clinics = Clinique::withoutGlobalScopes()->whereHas('societe', fn ($q) => $q->where('organisation_id', $patient->organisation_id))->pluck('id');
        // Filtré ou cherché, tout ce qui est de la sorte est lu (jusqu'au plafond), puis gardé ou non : la page et le curseur portent sur toute l'histoire
        $isNarrowed = $filter !== null || $search !== null;
        $limit = $isNarrowed ? (self::$narrowedLimitForTests ?? self::NARROWED_LIMIT) : self::PAGE_SIZE + 1 + $skip;
        $keep = fn (array $x): bool => $this->matchesFilter($x, $filter) && ($search === null || str_contains($this->haystack($x), $search));

        // Ordre total : la date, puis la sorte, la source et le numéro dans la même seconde
        $past = collect([
            ...($wants('visite') ? [...$this->appointments($patient, $clinics, $before, $limit), ...$this->visits($patient, $before, $limit)] : []),
            ...($wants('vaccin', 'vaccination') ? $this->vaccinations($patient, $before, $limit) : []),
            ...($wants('poids') ? $this->weighings($patient, $before, $limit) : []),
            ...($wants('prescription') ? $this->prescriptions($patient, $before, $limit) : []),
            ...($wants('examen', 'imagerie') ? $this->imaging($patient, $before, $limit) : []),
            ...($wants('examen', 'analyse') ? $this->labTests($patient, $before, $limit) : []),
            ...($wants('examen', 'releve_oph') ? $this->eyeExams($patient, $before, $limit) : []),
            ...($wants('document') ? $this->documents($patient, $before, $limit) : []),
        ])
            ->filter($keep)
            ->map(fn (array $x): array => [...$x, '_t' => CarbonImmutable::parse($x['date'])->utc()->getTimestamp()])
            ->sort(fn (array $a, array $b): int => [$b['_t'], $b['type'], $b['source'], $b['id']] <=> [$a['_t'], $a['type'], $a['source'], $a['id']])
            ->values();
        if ($before !== null) {
            $past = $past->slice(min($skip, $past->takeWhile(fn (array $x): bool => $x['_t'] === $before->getTimestamp())->count()))->values();
        }
        $page = $past->take(self::PAGE_SIZE)->values();
        $next = null;
        $nextSkip = 0;
        if ($past->count() > self::PAGE_SIZE) {
            $t = $past[self::PAGE_SIZE]['_t'];
            $next = CarbonImmutable::createFromTimestampUTC($t)->toIso8601String();
            $nextSkip = $page->filter(fn (array $x): bool => $x['_t'] === $t)->count()
                + ($before !== null && $t === $before->getTimestamp() ? $skip : 0);
        }
        $page = $page->map(fn (array $x): array => array_diff_key($x, ['_t' => true]));

        // Ce qui vient, en tête de la première page seulement : les rappels échus d'abord, puis le plus proche
        $upcoming = $before === null
            ? collect([
                ...($wants('visite') ? $this->upcomingAppointments($patient, $clinics) : []),
                ...($wants('vaccin', 'rappel') ? $this->dueReminders($patient) : []),
            ])->filter($keep)->sortBy(fn (array $x): int => CarbonImmutable::parse($x['date'])->getTimestamp())->values()->all()
            : [];

        return response()->json([
            'data' => [...$upcoming, ...$page->all()],
            'meta' => ['suivant' => $next, 'sauter' => $nextSkip],
        ]);
    }

    /** Une date du jour (pesée, vaccin…) se place à minuit, en UTC */
    private function day(CarbonInterface $date): string
    {
        return CarbonImmutable::parse($date->toDateString(), 'UTC')->toIso8601String();
    }

    /**
     * Une colonne de date du jour, jusqu'à `avant` inclus.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function upToDay(Builder $query, string $column, ?CarbonImmutable $before): Builder
    {
        return $before === null ? $query : $query->whereDate($column, '<=', $before->toDateString());
    }

    /**
     * @param  Collection<int, int>  $clinics
     * @return Builder<RendezVous>
     */
    private function appointmentQuery(Patient $patient, Collection $clinics): Builder
    {
        return RendezVous::withoutGlobalScopes()
            ->with(['lignePlanning' => fn ($q) => $q->withoutGlobalScopes(), 'compteRendu'])
            ->whereIn('clinique_id', $clinics)
            ->where('patient_id', $patient->id)
            ->where('type', '!=', 'tampon');
    }

    /**
     * Les rendez-vous passés : la consultation, son compte rendu (annulés exclus)
     *
     * @param  Collection<int, int>  $clinics
     * @return list<array<string, mixed>>
     */
    private function appointments(Patient $patient, Collection $clinics, ?CarbonImmutable $before, int $limit): array
    {
        return $this->appointmentQuery($patient, $clinics)
            ->where('status', '!=', StatutRendezVous::Annule)
            ->where(fn ($q) => $q->where('starts_at', '<', now())->orWhereIn('status', [StatutRendezVous::Fini, StatutRendezVous::Noshow]))
            ->when($before, fn ($q) => $q->where('starts_at', '<=', $before))
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (RendezVous $r): array => $this->appointmentLine($r, false))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, int>  $clinics
     * @return list<array<string, mixed>>
     */
    private function upcomingAppointments(Patient $patient, Collection $clinics): array
    {
        return $this->appointmentQuery($patient, $clinics)
            ->where('starts_at', '>=', now())
            ->whereIn('status', [StatutRendezVous::Prevu, StatutRendezVous::Salle, StatutRendezVous::Cours])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->map(fn (RendezVous $r): array => $this->appointmentLine($r, true))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function appointmentLine(RendezVous $r, bool $isUpcoming): array
    {
        // Des lignes notées seules ne font pas un compte rendu rédigé (04.11.2026)
        $report = $r->compteRendu?->estRedige() ? $r->compteRendu : null;
        $isSigned = $report !== null && $report->signe_le !== null;
        $status = match ($r->status) {
            StatutRendezVous::Noshow => 'Non venu',
            StatutRendezVous::Fini => null,
            default => $isUpcoming ? 'Prévu' : null,
        };
        $reportState = $report === null ? null : ($isSigned ? 'compte rendu signé' : 'compte rendu en cours');
        // La conclusion seulement d'un compte rendu signé : un brouillon n'est pas encore au dossier
        $conclusion = $isSigned && $report->a ? '« '.mb_strimwidth(trim($report->a), 0, 110, '…').' »' : null;

        return [
            'type' => 'visite', 'source' => 'rdv', 'id' => $r->id, 'date' => $r->starts_at->toIso8601String(), 'a_venir' => $isUpcoming,
            'titre' => $r->reason ?: 'Consultation',
            'detail' => implode(' · ', array_filter([$r->lignePlanning?->nom, $status, $reportState, $conclusion])),
            'rendez_vous_id' => $r->id,
            'clinique_id' => $r->clinique_id,
            'veterinaire' => $r->lignePlanning?->nom,
            'compte_rendu' => $report === null ? null : ['signe' => $isSigned],
        ];
    }

    /**
     * Les visites notées au dossier (avant les rendez-vous en ligne)
     *
     * @return list<array<string, mixed>>
     */
    private function visits(Patient $patient, ?CarbonImmutable $before, int $limit): array
    {
        return $this->upToDay($patient->visites()->with(['employe' => fn ($q) => $q->withoutGlobalScope('clinique')])->getQuery(), 'visite_le', $before)
            ->orderByDesc('visite_le')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Visite $v): array => [
                'type' => 'visite', 'source' => 'visite', 'id' => $v->id, 'date' => $this->day($v->visite_le), 'a_venir' => false,
                'titre' => $v->motif ?: 'Visite',
                'detail' => implode(' · ', array_filter([$v->employe?->nom, $v->note])),
                'veterinaire' => $v->employe?->nom,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function vaccinations(Patient $patient, ?CarbonImmutable $before, int $limit): array
    {
        return $this->upToDay($patient->vaccinations()->with(['employe' => fn ($q) => $q->withoutGlobalScope('clinique'), 'produit.valences'])->getQuery(), 'injectee_le', $before)
            ->orderByDesc('injectee_le')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (Vaccination $v): array {
                // Les maladies couvertes, comme le carnet (EtatVaccinal)
                $diseases = array_values(array_map(fn (array $d): string => $d['court'], EtatVaccinal::valencesDe($v)));

                return [
                    'type' => 'vaccin', 'source' => 'vaccination', 'id' => $v->id, 'date' => $this->day($v->injectee_le), 'a_venir' => false,
                    'titre' => $v->nom ?: ($v->marque ?: 'Vaccin'),
                    'detail' => implode(' · ', array_filter([implode(', ', $diseases) ?: null, $v->marque, $v->lot ? 'lot '.$v->lot : null, $v->employe?->nom])),
                    'vaccination_id' => $v->id,
                    'employe_id' => $v->employe_id,
                    'produit' => $v->marque,
                    'lot' => $v->lot,
                    'valences' => $diseases,
                    'echeance_le' => $v->echeance_le?->toDateString(),
                    'est_renouvelee' => (bool) $v->getAttribute('renouvelee'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Les rappels : chaque vaccin pas encore renouvelé qui a une échéance ;
     * un échu vient en tête, marqué en retard
     *
     * @return list<array<string, mixed>>
     */
    private function dueReminders(Patient $patient): array
    {
        $today = aujourdhui()->toDateString();

        return $patient->vaccinations()
            ->nonRenouvelees()
            ->whereNotNull('echeance_le')
            ->withMax('rappelsEnvoyes as rappel_envoye_le', 'envoye_le')
            ->orderBy('echeance_le')
            ->orderBy('id')
            ->get()
            ->map(function (Vaccination $v) use ($today): array {
                $sentAt = $v->getAttribute('rappel_envoye_le');
                $isLate = $v->echeance_le->toDateString() < $today;

                return [
                    'type' => 'vaccin', 'source' => 'rappel', 'id' => $v->id, 'date' => $this->day($v->echeance_le), 'a_venir' => true,
                    'en_retard' => $isLate,
                    'titre' => 'Rappel : '.($v->nom ?: $v->marque),
                    'detail' => implode(' · ', array_filter([
                        ($isLate ? 'Échu le ' : 'Prévu le ').$v->echeance_le->format('d.m.Y'),
                        $sentAt ? 'rappel envoyé le '.CarbonImmutable::parse($sentAt)->format('d.m.Y') : null,
                    ])),
                    'vaccination_id' => $v->id,
                    'echeance_le' => $v->echeance_le->toDateString(),
                    'rappel_envoye_le' => $sentAt ? CarbonImmutable::parse($sentAt)->toDateString() : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Les pesées, chacune avec l'écart sur la précédente (servi, jamais recalculé à l'écran)
     *
     * @return list<array<string, mixed>>
     */
    private function weighings(Patient $patient, ?CarbonImmutable $before, int $limit): array
    {
        $all = $patient->pesees()->orderBy('pesee_le')->orderBy('id')->get()->values();
        $lines = [];
        foreach ($all as $i => $weighing) {
            /** @var Pesee $weighing */
            $previous = $i > 0 ? $all[$i - 1] : null;
            $kg = round((float) $weighing->poids_kg, 2);
            $lines[] = [
                'type' => 'poids', 'source' => 'pesee', 'id' => $weighing->id, 'date' => $this->day($weighing->pesee_le), 'a_venir' => false,
                'titre' => str_replace('.', ',', (string) round($kg, 1)).' kg',
                'detail' => null,
                'pesee_id' => $weighing->id,
                'poids_kg' => $kg,
                'ecart_kg' => $previous ? round($kg - (float) $previous->poids_kg, 2) : null,
                'precedente_le' => $previous?->pesee_le->toDateString(),
            ];
        }

        $newestFirst = collect($lines)
            ->filter(fn (array $x): bool => $before === null || substr($x['date'], 0, 10) <= $before->toDateString())
            ->reverse();

        return $newestFirst->take($limit)->values()->all();
    }

    /**
     * Les ordonnances : les médicaments (et leur code ATC), le traitement en
     * cours et ses jours. Sans durée lisible, l'ordonnance est en cours tant
     * qu'elle est valable : trente jours après sa date (il n'y a pas de date
     * de validité au registre), puis terminée.
     *
     * @return list<array<string, mixed>>
     */
    private function prescriptions(Patient $patient, ?CarbonImmutable $before, int $limit): array
    {
        $today = aujourdhui()->startOfDay();

        return $this->upToDay(Ordonnance::query()
            ->with(['lignes.produit', 'employe' => fn ($q) => $q->withoutGlobalScope('clinique')])
            ->where('patient_id', $patient->id), 'signee_le', $before)
            ->orderByDesc('signee_le')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            // Les principes actifs de tous les médicaments, en une requête
            ->tap(fn ($prescriptions) => Produit::withCatalogueSubstances($prescriptions->flatMap(fn (Ordonnance $o) => $o->lignes->pluck('produit'))))
            ->map(function (Ordonnance $o) use ($today): array {
                $durations = $o->lignes->map(fn (OrdonnanceLigne $l): ?int => TreatmentDuration::days($l->duree))->filter()->values();
                $plannedDays = $durations->isEmpty() ? null : (int) $durations->max();
                $signedOn = $o->signee_le->copy()->startOfDay();
                $day = (int) $signedOn->diffInDays($today) + 1;
                $validUntil = $plannedDays === null ? $signedOn->copy()->addDays(TreatmentDuration::DEFAULT_VALIDITY_DAYS) : null;
                $isActive = $o->statut === StatutOrdonnance::Active;
                $isOngoing = $isActive && ($plannedDays !== null ? $day <= $plannedDays : $today->lte($validUntil));
                $first = $o->lignes->first();

                return [
                    'type' => 'prescription', 'source' => 'ordonnance', 'id' => $o->id, 'date' => $this->day($o->signee_le), 'a_venir' => false,
                    'titre' => $o->lignes->map(fn (OrdonnanceLigne $l): string => (string) $l->produit?->nom)->filter()->join(' et ') ?: 'Ordonnance '.$o->numero,
                    'detail' => implode(' · ', array_filter([
                        $first ? trim($first->posologie.', '.$first->duree, ' ,') : null,
                        $o->renouvellements > 0 ? $o->renouvellements.' renouvellement'.($o->renouvellements > 1 ? 's' : '') : null,
                        $o->employe?->nom,
                        ! $isActive ? 'clôturée' : (! $isOngoing ? 'terminée' : null),
                    ])),
                    'ordonnance_id' => $o->id,
                    'numero' => $o->numero,
                    'statut' => $o->statut->value,
                    'signataire' => $o->employe?->nom,
                    'renouvellements' => $o->renouvellements,
                    'lignes' => $o->lignes->map(fn (OrdonnanceLigne $l): array => [
                        'produit_nom' => $l->produit?->nom,
                        'posologie' => $l->posologie,
                        'duree' => $l->duree,
                        'atc_code' => $l->produit?->atc_code,
                        'substances' => $l->produit?->catalogueSubstances() ?? [],
                    ])->values()->all(),
                    // Le traitement en cours : jours faits sur jours prévus (la durée écrite la plus longue)
                    'jours_prevus' => $plannedDays,
                    'jour' => $plannedDays === null ? $day : min($day, $plannedDays),
                    'valable_jusqu_au' => $validUntil?->toDateString(),
                    'en_cours' => $isOngoing,
                ];
            })
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function imaging(Patient $patient, ?CarbonImmutable $before, int $limit): array
    {
        return $patient->examensImagerie()
            ->withoutGlobalScope('clinique')
            ->withCount('cliches')
            ->when($before, fn ($q) => $q->where('realise_le', '<=', $before))
            ->orderByDesc('realise_le')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (ExamenImagerie $e): array {
                $count = (int) $e->getAttribute('cliches_count');
                $modality = $e->modalite->value;

                return [
                    'type' => 'examen', 'source' => 'imagerie', 'id' => $e->id, 'date' => $e->realise_le->toIso8601String(), 'a_venir' => false,
                    'titre' => ['DX' => 'Radio', 'US' => 'Échographie', 'ES' => 'Endoscopie'][$modality].' : '.$e->libelle,
                    'detail' => implode(' · ', array_filter([
                        $count > 0 ? $count.' cliché'.($count > 1 ? 's' : '') : null,
                        $e->estSigne() ? ($e->conclusion ? '« '.mb_strimwidth(trim($e->conclusion), 0, 110, '…').' »' : 'compte rendu signé') : 'compte rendu à faire',
                    ])),
                    'examen_id' => $e->id,
                    'clinique_id' => $e->clinique_id,
                    'modalite' => $modality,
                    'libelle' => $e->libelle,
                    'est_signe' => $e->estSigne(),
                    'nb_cliches' => $count,
                    'conclusion' => $e->estSigne() ? $e->conclusion : null,
                ];
            })
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function labTests(Patient $patient, ?CarbonImmutable $before, int $limit): array
    {
        return $patient->analyses()
            ->withoutGlobalScope('clinique')
            ->with(['panels', 'resultats'])
            ->when($before, fn ($q) => $q->where('preleve_le', '<=', $before))
            ->orderByDesc('preleve_le')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (Analyse $a): array {
                $outOfRange = $a->resultats->whereNotNull('drapeau')->count();

                return [
                    'type' => 'examen', 'source' => 'analyse', 'id' => $a->id, 'date' => $a->preleve_le->toIso8601String(), 'a_venir' => false,
                    'titre' => 'Analyse : '.($a->panels->pluck('nom')->join(', ') ?: $a->numero),
                    'detail' => implode(' · ', array_filter([
                        $a->numero,
                        match ($a->statut) {
                            StatutAnalyse::Attente => 'en attente',
                            StatutAnalyse::Cours => 'en cours',
                            StatutAnalyse::AValider => 'à valider',
                            StatutAnalyse::Valide => 'validée',
                        },
                        $outOfRange > 0 ? $outOfRange.' valeur'.($outOfRange > 1 ? 's' : '').' hors normes' : null,
                    ])),
                    'analyse_id' => $a->id,
                    'clinique_id' => $a->clinique_id,
                    'numero' => $a->numero,
                    'statut' => $a->statut->value,
                    'hors_normes' => $outOfRange,
                ];
            })
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function eyeExams(Patient $patient, ?CarbonImmutable $before, int $limit): array
    {
        return $this->upToDay($patient->relevesOphtalmologiques()->getQuery(), 'releve_le', $before)
            ->orderByDesc('releve_le')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (ReleveOphtalmologique $r): array => [
                'type' => 'examen', 'source' => 'releve_oph', 'id' => $r->id, 'date' => $this->day($r->releve_le), 'a_venir' => false,
                'titre' => 'Relevé ophtalmologique',
                // Seulement les mesures prises (œil droit puis gauche)
                'detail' => implode(' · ', array_filter([
                    $this->eyePair('Schirmer', $r->schirmer_od, $r->schirmer_og),
                    $this->eyePair('pression', $r->pio_od, $r->pio_og),
                    $r->notes,
                ])),
                'releve_id' => $r->id,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function documents(Patient $patient, ?CarbonImmutable $before, int $limit): array
    {
        return $this->upToDay($patient->documents()->getQuery(), 'etabli_le', $before)
            ->orderByDesc('etabli_le')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Document $d): array => [
                'type' => 'document', 'source' => 'document', 'id' => $d->id, 'date' => $this->day($d->etabli_le), 'a_venir' => false,
                'titre' => $d->nom,
                'detail' => implode(' · ', array_filter([$d->signataire, $d->visible_proprietaire === false ? 'interne' : 'partagé avec le propriétaire'])),
                'document_id' => $d->id,
                'document_type' => $d->type,
                'a_fichier' => $d->chemin !== null,
                'est_pdf' => $d->nom_fichier !== null,
                'visible_proprietaire' => $d->visible_proprietaire !== false,
            ])
            ->values()
            ->all();
    }

    /**
     * Le filtre d'un onglet (FILTERS) : les rappels, les injections encore
     * valables ou renouvelées ; les pesées des trois ou de la dernière année ;
     * les ordonnances en cours, renouvelables ou terminées ; une sorte
     * d'examen, ou les analyses hors normes.
     *
     * @param  array<string, mixed>  $x
     */
    private function matchesFilter(array $x, ?string $filter): bool
    {
        return match ($filter) {
            null => true,
            'due' => $x['source'] === 'rappel',
            'current' => $x['source'] === 'vaccination' && ! $x['est_renouvelee'],
            'renewed' => $x['source'] === 'vaccination' && $x['est_renouvelee'],
            'trois', 'an' => substr($x['date'], 0, 10) >= aujourdhui()->subYears($filter === 'an' ? 1 : 3)->toDateString(),
            'active' => (bool) ($x['en_cours'] ?? false),
            'renew' => ($x['renouvellements'] ?? 0) > 0,
            'closed' => ! ($x['en_cours'] ?? false),
            'imaging' => $x['source'] === 'imagerie',
            'lab' => $x['source'] === 'analyse',
            'eye' => $x['source'] === 'releve_oph',
            'out' => ($x['hors_normes'] ?? 0) > 0,
            default => false,
        };
    }

    /**
     * Ce que la recherche lit d'une ligne : son titre, son détail, ses
     * numéros, noms et médicaments, et sa date écrite comme à l'écran
     * (« 12.03.2025 », « 12 mars 2025 »), sans casse ni accents.
     *
     * @param  array<string, mixed>  $x
     */
    private function haystack(array $x): string
    {
        $day = CarbonImmutable::parse($x['date'])->setTimezone($this->zone());
        $parts = [
            $x['titre'] ?? '', $x['detail'] ?? '', $x['numero'] ?? '', $x['signataire'] ?? '', $x['veterinaire'] ?? '',
            $x['produit'] ?? '', $x['lot'] ?? '', $x['libelle'] ?? '', $x['conclusion'] ?? '',
            implode(' ', $x['valences'] ?? []),
            ...array_map(fn (array $l): string => implode(' ', [$l['produit_nom'] ?? '', $l['posologie'] ?? '', $l['duree'] ?? '']), $x['lignes'] ?? []),
            $day->format('d.m.Y'),
            $day->day.' '.self::MONTHS[$day->month - 1].' '.$day->year,
        ];

        return $this->normalized(implode(' | ', $parts));
    }

    /** Sans casse ni accents (« Échographie » se trouve avec « echo ») */
    private function normalized(string $text): string
    {
        return Str::lower(Str::ascii($text));
    }

    private ?string $zone = null;

    /** Le fuseau de la clinique du jeton, pour la date écrite d'une ligne */
    private function zone(): string
    {
        return $this->zone ??= (app(ContexteClinique::class)->clinique()?->fuseau_horaire ?: 'Europe/Zurich');
    }

    /** « Schirmer 15 et 17 », « pression 18 à droite » ; rien sans mesure */
    private function eyePair(string $label, mixed $right, mixed $left): ?string
    {
        return match (true) {
            $right !== null && $left !== null => $label.' '.$right.' et '.$left,
            $right !== null => $label.' '.$right.' à droite',
            $left !== null => $label.' '.$left.' à gauche',
            default => null,
        };
    }
}
