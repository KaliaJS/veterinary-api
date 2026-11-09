<?php

namespace App\Services\Workday;

use App\Enums\DeclarationStatus;
use App\Enums\DomainePermission;
use App\Enums\ServiceClinique;
use App\Enums\StatutAnalyse;
use App\Enums\StatutIntervention;
use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Models\Analyse;
use App\Models\AntibioticDeclaration;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\ExamenImagerie;
use App\Models\Intervention;
use App\Models\IsabvConnection;
use App\Models\RendezVous;
use App\Models\Sejour;
use App\Models\StaySheet;
use App\Services\Hospitalisation\StaySheets;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * « À terminer » (choix de Jim, 08.11.2026) : le travail d'un
 * vétérinaire qui doit être bouclé avant de partir, et la part déjà faite.
 *
 * Chaque chose a un jour d'origine (le rendez-vous, l'intervention, la
 * feuille d'hospitalisation, le prélèvement, l'examen). Le pourcentage
 * compte ce qui vient d'aujourd'hui et qui est fait, sur ce qui vient
 * d'aujourd'hui plus tout ce qui reste des jours d'avant, sans limite
 * (choix de Jim). Neuf sortes :
 * - compte rendu à rédiger : consultation, contrôle, urgence, fin de vie
 *   ou téléconsultation finie, sans compte rendu rédigé ;
 * - compte rendu à signer : rédigé, pas signé (seulement pour qui a le
 *   droit de signer ; sans lui, rédigé suffit) ;
 * - rapport de bloc : intervention finie dont le rapport n'est pas
 *   validé (09.11.2026 : la note seule ne suffit plus), ou d'un jour
 *   d'avant jamais close ;
 * - consentement : intervention sans consentement recueilli (signé, oral
 *   ou urgence), y compris celles prévues plus tard dans la journée ;
 * - rapport d'hospitalisation : chaque jour d'un séjour dont il est le
 *   vétérinaire, tant que le rapport du jour n'est pas signé ;
 * - analyse à valider (droit de valider) et imagerie à interpréter (droit
 *   de signer), qu'il a prescrites ;
 * - rendez-vous resté ouvert : passé, ni fini ni non venu ;
 * - déclaration SI ABV à compléter, refusée par l'OSAV ou pas encore
 *   partie (clinique qui déclare par Veterinary, droit de prescrire).
 *
 * `reminder_at` : quinze minutes après la fin de son dernier rendez-vous ou
 * de sa dernière intervention du jour ; null s'il n'en a aucun.
 */
class UnfinishedWork
{
    /** Les types de rendez-vous qui demandent un compte rendu (comme Ma journée) */
    private const REPORT_TYPES = [TypeRendezVous::Consultation, TypeRendezVous::Controle, TypeRendezVous::Urgence, TypeRendezVous::Euthanasie, TypeRendezVous::Teleconsultation];

    /** Lignes servies par sorte ; le nombre, lui, est toujours complet */
    public const MAX_LINES = 100;

    /** Les sortes, dans l'ordre de la liste */
    public const KINDS = ['report_to_write', 'report_to_sign', 'surgery_report', 'consent', 'stay_report', 'lab', 'imaging', 'open_appointment', 'antibiotic_declaration'];

    /** @var array<string, array{count: int, lines: list<array<string, mixed>>}> */
    private array $remaining = [];

    private int $doneToday = 0;

    private CarbonImmutable $dayStart;

    private CarbonImmutable $dayEnd;

    private string $timezone;

    public function __construct(private StaySheets $sheets) {}

    /**
     * Chaque sorte n'est comptée que si la personne a le droit (et la
     * clinique le service) de la boucler : sinon son geste finirait en refus.
     *
     * @param  callable(DomainePermission): bool  $can
     * @return array<string, mixed>
     */
    public function for(Employe $employe, Clinique $clinique, callable $can): array
    {
        $this->timezone = $clinique->fuseau_horaire ?: 'Europe/Zurich';
        $this->dayStart = CarbonImmutable::now($this->timezone)->startOfDay()->utc();
        $this->dayEnd = $this->dayStart->addDay();
        $this->remaining = array_fill_keys(self::KINDS, ['count' => 0, 'lines' => []]);
        $this->doneToday = 0;

        $canSign = $can(DomainePermission::SignerComptesRendus);
        if ($can(DomainePermission::Planning)) {
            $this->appointments($employe, $canSign);
        }
        if ($can(DomainePermission::Bloc) && $clinique->aService(ServiceClinique::Chirurgie)) {
            $this->surgeries($employe);
        }
        if ($clinique->aService(ServiceClinique::Hospitalisation)) {
            $this->stays($employe);
        }
        if ($can(DomainePermission::ValiderAnalyses) && $clinique->aService(ServiceClinique::Laboratoire)) {
            $this->labs($employe);
        }
        if ($canSign && $clinique->aService(ServiceClinique::Imagerie)) {
            $this->imaging($employe);
        }
        if ($can(DomainePermission::Prescrire) && IsabvConnection::activeFor($clinique->id) !== null) {
            $this->antibioticDeclarations($employe);
        }

        $left = array_sum(array_column($this->remaining, 'count'));
        $total = $this->doneToday + $left;

        return [
            'is_concerned' => true,
            'percent' => $total > 0 ? intdiv($this->doneToday * 100, $total) : null,
            'done' => $this->doneToday,
            'left' => $left,
            'total' => $total,
            'reminder_at' => $this->reminderAt($employe)?->toIso8601String(),
            'groups' => array_values(array_map(
                fn (string $kind) => ['kind' => $kind, ...$this->remaining[$kind]],
                array_filter(self::KINDS, fn (string $kind) => $this->remaining[$kind]['count'] > 0),
            )),
        ];
    }

    /**
     * Les rendez-vous du vétérinaire : sa ligne de planning
     *
     * @return Builder<RendezVous>
     */
    private function mine(Employe $employe): Builder
    {
        return RendezVous::query()->whereHas('lignePlanning', fn (Builder $q) => $q->where('employe_id', $employe->id));
    }

    private function appointments(Employe $employe, bool $canSign): void
    {
        // Comptes rendus : rendez-vous finis qui en demandent un, jusqu'à la fin d'aujourd'hui
        // Les jours d'avant : seulement ce qui reste (pas de compte rendu rédigé, ou pas signé), filtré en base
        $finished = $this->mine($employe)
            ->where('status', StatutRendezVous::Fini)
            ->whereIn('type', self::REPORT_TYPES)
            ->where('starts_at', '<', $this->dayEnd)
            ->where(fn (Builder $q) => $q->where('starts_at', '>=', $this->dayStart)
                ->orWhereDoesntHave('compteRendu', fn (Builder $c) => $c->redige())
                ->when($canSign, fn (Builder $q) => $q->orWhereHas('compteRendu', fn (Builder $c) => $c->whereNull('signe_le'))))
            ->with('compteRendu')
            ->orderBy('starts_at')
            ->get();
        foreach ($finished as $rdv) {
            $report = $rdv->compteRendu;
            $isWritten = $report !== null && $report->estRedige();
            $isDone = $isWritten && (! $canSign || $report->signe_le !== null);
            $isToday = $rdv->starts_at >= $this->dayStart;
            if ($this->count($isToday, $isDone)) {
                $this->add($isWritten ? 'report_to_sign' : 'report_to_write', [
                    'id' => $rdv->id,
                    'patient' => $rdv->patient_name,
                    'label' => $rdv->reason,
                    'appointment_type' => $rdv->type->value,
                    'at' => $rdv->starts_at->toIso8601String(),
                    'is_earlier' => ! $isToday,
                    'target' => ['type' => 'appointment', 'id' => $rdv->id],
                ]);
            }
        }

        // Rendez-vous passés restés ouverts ; la chirurgie vit au bloc, les plages tampon n'en sont pas
        $past = $this->mine($employe)
            ->where('ends_at', '<=', now())
            ->whereNotIn('type', [TypeRendezVous::Chirurgie, TypeRendezVous::Tampon])
            ->where('status', '!=', StatutRendezVous::Annule)
            ->where(fn (Builder $q) => $q->where('starts_at', '>=', $this->dayStart)
                ->orWhereIn('status', [StatutRendezVous::Prevu, StatutRendezVous::Salle, StatutRendezVous::Cours]))
            ->orderBy('starts_at')
            ->get();
        foreach ($past as $rdv) {
            $isToday = $rdv->starts_at >= $this->dayStart;
            $isDone = in_array($rdv->status, [StatutRendezVous::Fini, StatutRendezVous::Noshow], true);
            if ($this->count($isToday, $isDone)) {
                $this->add('open_appointment', [
                    'id' => $rdv->id,
                    'patient' => $rdv->patient_name,
                    'label' => $rdv->reason,
                    'appointment_type' => $rdv->type->value,
                    'status' => $rdv->status->value,
                    'at' => $rdv->starts_at->toIso8601String(),
                    'is_earlier' => ! $isToday,
                    'target' => ['type' => 'appointment', 'id' => $rdv->id],
                ]);
            }
        }
    }

    private function surgeries(Employe $employe): void
    {
        $surgeries = Intervention::query()
            ->where('chirurgien_id', $employe->id)
            ->where('prevu_le', '<', $this->dayEnd)
            ->where(fn (Builder $q) => $q->where('prevu_le', '>=', $this->dayStart)
                // Rapport de bloc pas encore validé (09.11.2026 : la note seule ne suffit plus)
                ->orWhere(fn (Builder $q) => $q->where('statut', StatutIntervention::Fini)->whereDoesntHave('report', fn (Builder $r) => $r->where('status', 'validated')))
                ->orWhere(fn (Builder $q) => $q->whereNull('consentement_signe_le')->whereNull('consent_method'))
                // Un jour d'avant, jamais close (prévue, en cours, au réveil)
                ->orWhere('statut', '!=', StatutIntervention::Fini))
            ->with(['patient:id,nom', 'report:id,intervention_id,status'])
            ->orderBy('prevu_le')
            ->get();
        foreach ($surgeries as $surgery) {
            $isToday = $surgery->prevu_le >= $this->dayStart;
            $line = [
                'id' => $surgery->id,
                'patient' => $surgery->patient?->nom,
                'label' => $surgery->procedure,
                'at' => $surgery->prevu_le->toIso8601String(),
                'is_earlier' => ! $isToday,
                'target' => ['type' => 'surgery', 'id' => $surgery->id],
                // Close ou non : le rapport se rédige dans sa fenêtre, une intervention jamais close se termine au bloc
                'status' => $surgery->statut->value,
            ];
            if ($surgery->statut === StatutIntervention::Fini) {
                $isValidated = $surgery->report?->status === 'validated';
                if ($this->count($isToday, $isValidated)) {
                    $this->add('surgery_report', $line);
                }
            } elseif (! $isToday) {
                // Jamais close : son rapport ne peut pas être fait, elle reste à terminer
                $this->count(false, false);
                $this->add('surgery_report', $line);
            }
            $hasConsent = $surgery->consentement_signe_le !== null || $surgery->consent_method !== null;
            if ($this->count($isToday, $hasConsent)) {
                $this->add('consent', $line);
            }
        }
    }

    /** Un rapport par jour de séjour, de l'admission à la sortie (ou à aujourd'hui) */
    private function stays(Employe $employe): void
    {
        // Les séjours, avec le nombre de leurs rapports signés ; seuls ceux à qui il en manque lisent leurs feuilles (en une requête)
        $stays = Sejour::query()->where('veterinaire_id', $employe->id)->with(['patient:id,nom', 'clinique'])->orderBy('admis_le')->get();
        if ($stays->isEmpty()) {
            return;
        }
        $signedCounts = StaySheet::query()->whereIn('sejour_id', $stays->modelKeys())->where('report_status', StaySheet::REPORT_SIGNED)
            ->groupBy('sejour_id')->selectRaw('sejour_id, count(*) as signed')->pluck('signed', 'sejour_id')->map(fn ($n) => (int) $n)->all();
        $open = [];
        foreach ($stays as $stay) {
            $first = $this->sheets->admissionDay($stay);
            $last = $this->sheets->lastDay($stay);
            $today = $this->sheets->today($stay);
            $days = (int) CarbonImmutable::parse($first)->diffInDays(CarbonImmutable::parse($last)) + 1;
            if (($signedCounts[$stay->id] ?? 0) >= $days) {
                // Tout est signé : seul le rapport d'aujourd'hui compte, fait
                if ($first <= $today && $today <= $last) {
                    $this->count(true, true);
                }

                continue;
            }
            $open[] = [$stay, $first, $last, $today];
        }
        if (! $open) {
            return;
        }
        // L'état du rapport de chaque feuille ouverte : « none » pour un jour sans feuille
        $statuses = [];
        StaySheet::query()->whereIn('sejour_id', array_map(fn (array $o) => $o[0]->id, $open))->get(['sejour_id', 'day', 'report_status'])
            ->each(function (StaySheet $sheet) use (&$statuses): void {
                $statuses[$sheet->sejour_id][$sheet->day->toDateString()] = $sheet->report_status;
            });
        foreach ($open as [$stay, $first, $last, $today]) {
            for ($day = CarbonImmutable::parse($first); $day->toDateString() <= $last; $day = $day->addDay()) {
                $key = $day->toDateString();
                $isToday = $key === $today;
                $status = $statuses[$stay->id][$key] ?? StaySheet::REPORT_NONE;
                if ($this->count($isToday, $status === StaySheet::REPORT_SIGNED)) {
                    $this->add('stay_report', [
                        'id' => $stay->id.':'.$key,
                        'patient' => $stay->patient?->nom,
                        'label' => null,
                        'box' => $stay->box,
                        'day' => $key,
                        'report_status' => $status,
                        'is_earlier' => ! $isToday,
                        'target' => ['type' => 'stay', 'id' => $stay->id, 'day' => $key],
                    ]);
                }
            }
        }
    }

    private function labs(Employe $employe): void
    {
        $labs = Analyse::query()
            ->where('prescripteur_id', $employe->id)
            ->where(fn (Builder $q) => $q->where('statut', StatutAnalyse::AValider)
                ->orWhere(fn (Builder $q) => $q->where('statut', StatutAnalyse::Valide)->whereBetween('preleve_le', [$this->dayStart, $this->dayEnd])))
            ->with(['patient:id,nom', 'panels:id,analyse_id,nom'])
            ->orderBy('preleve_le')
            ->get();
        foreach ($labs as $lab) {
            $isDone = $lab->statut === StatutAnalyse::Valide;
            // Comme les autres sortes, l'analyse est du jour de son prélèvement
            $isToday = $lab->preleve_le >= $this->dayStart;
            if ($this->count($isToday, $isDone)) {
                $this->add('lab', [
                    'id' => $lab->id,
                    'patient' => $lab->patient?->nom,
                    'label' => $lab->panels->pluck('nom')->implode(', '),
                    'at' => $lab->preleve_le->toIso8601String(),
                    'is_earlier' => ! $isToday,
                    'target' => ['type' => 'lab', 'id' => $lab->id],
                ]);
            }
        }
    }

    private function imaging(Employe $employe): void
    {
        $exams = ExamenImagerie::query()
            ->where('prescripteur_id', $employe->id)
            ->where('realise_le', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('signe_le')->orWhere('realise_le', '>=', $this->dayStart))
            ->with('patient:id,nom')
            ->orderBy('realise_le')
            ->get();
        foreach ($exams as $exam) {
            $isToday = $exam->realise_le >= $this->dayStart;
            if ($this->count($isToday, $exam->signe_le !== null)) {
                $this->add('imaging', [
                    'id' => $exam->id,
                    'patient' => $exam->patient?->nom,
                    'label' => $exam->libelle,
                    'at' => $exam->realise_le->toIso8601String(),
                    'is_earlier' => ! $isToday,
                    'target' => ['type' => 'imaging', 'id' => $exam->id],
                ]);
            }
        }
    }

    /**
     * SI ABV (08.11.2026) : ses déclarations d'antibiotiques à compléter,
     * refusées par l'OSAV ou pas encore parties ; du jour de la consultation.
     * Celles du jour parties (ou saisies sur le site de l'OSAV) sont faites.
     */
    private function antibioticDeclarations(Employe $employe): void
    {
        $today = $this->dayStart->setTimezone($this->timezone)->toDateString();
        // Les siennes, et celles sans vétérinaire (ou dont le contrat est fini) : à chacun qui prescrit
        $declarations = AntibioticDeclaration::query()
            ->where(fn (Builder $q) => $q->where('employe_id', $employe->id)->orWhereNull('employe_id')
                ->orWhereIn('employe_id', Employe::query()->withoutGlobalScopes()->whereNotNull('fin_contrat_le')->where('fin_contrat_le', '<', $today)->select('id')))
            ->where(fn (Builder $q) => $q->whereIn('status', [DeclarationStatus::ToComplete, DeclarationStatus::Rejected, DeclarationStatus::Ready])
                ->orWhere(fn (Builder $q) => $q->whereDate('visit_on', $today)->whereIn('status', [DeclarationStatus::Sent, DeclarationStatus::EnteredOnWeb])))
            ->with('patient:id,nom')
            ->orderBy('visit_on')
            ->get();
        foreach ($declarations as $declaration) {
            $isToday = $declaration->visit_on->toDateString() === $today;
            // Faite une fois partie (ou saisie sur le site de l'OSAV) ; prête, elle n'est pas encore partie
            $isDone = in_array($declaration->status, [DeclarationStatus::Sent, DeclarationStatus::EnteredOnWeb], true);
            if ($this->count($isToday, $isDone)) {
                $this->add('antibiotic_declaration', [
                    'id' => $declaration->id,
                    'patient' => $declaration->patient?->nom,
                    'label' => match ($declaration->status) {
                        DeclarationStatus::Rejected => 'Refusée par l’OSAV',
                        DeclarationStatus::Ready => 'Pas encore partie',
                        default => 'À compléter',
                    },
                    'status' => $declaration->status->value,
                    'day' => $declaration->visit_on->toDateString(),
                    'is_earlier' => ! $isToday,
                    'target' => ['type' => 'antibiotic_declaration', 'id' => $declaration->id],
                ]);
            }
        }
    }

    /**
     * Compte une chose : faite, seulement si elle est d'aujourd'hui ; à
     * faire, quel que soit son jour (elle entre alors dans la liste). Vrai
     * si elle reste à faire.
     */
    private function count(bool $isToday, bool $isDone): bool
    {
        if ($isToday && $isDone) {
            $this->doneToday++;
        }

        return ! $isDone;
    }

    /** @param array<string, mixed> $line */
    private function add(string $kind, array $line): void
    {
        $this->remaining[$kind]['count']++;
        if (count($this->remaining[$kind]['lines']) < self::MAX_LINES) {
            $this->remaining[$kind]['lines'][] = $line;
        }
    }

    private function reminderAt(Employe $employe): ?CarbonImmutable
    {
        // Ce qui touche aujourd'hui, y compris commencé la veille et fini après minuit (une urgence de 23:30 à 00:20)
        $lastAppointment = $this->mine($employe)
            ->where('starts_at', '<', $this->dayEnd)
            ->where('ends_at', '>=', $this->dayStart)
            ->whereNotIn('type', [TypeRendezVous::Tampon])
            ->whereNotIn('status', [StatutRendezVous::Annule])
            ->max('ends_at');
        $lastSurgery = Intervention::query()
            ->where('chirurgien_id', $employe->id)
            ->whereBetween('prevu_le', [$this->dayStart->subDay(), $this->dayEnd])
            ->get(['prevu_le', 'duree_minutes'])
            ->map(fn (Intervention $i) => CarbonImmutable::parse($i->prevu_le)->addMinutes($i->duree_minutes))
            ->filter(fn (CarbonImmutable $end) => $end >= $this->dayStart)
            ->max();
        $ends = array_filter([$lastAppointment ? CarbonImmutable::parse($lastAppointment, 'UTC') : null, $lastSurgery]);

        return $ends ? max($ends)->addMinutes(15)->utc() : null;
    }
}
