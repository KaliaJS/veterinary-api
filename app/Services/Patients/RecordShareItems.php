<?php

namespace App\Services\Patients;

use App\Enums\DrapeauResultatLabo;
use App\Enums\StatutAnalyse;
use App\Enums\StatutRendezVous;
use App\Models\Analyse;
use App\Models\AnalyseResultat;
use App\Models\ClicheDicom;
use App\Models\Clinique;
use App\Models\DischargeLetter;
use App\Models\Document;
use App\Models\ExamenImagerie;
use App\Models\Ordonnance;
use App\Models\OrdonnanceLigne;
use App\Models\Patient;
use App\Models\ReferralLetter;
use App\Models\RendezVous;
use App\Models\Sejour;
use App\Models\StaySheet;
use App\Models\SurgeryReport;
use App\Models\Vaccination;
use App\Models\Visite;
use App\Services\Hospitalisation\DischargeLetters;
use App\Services\Referrals\ReferralLetters;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Les pièces du dossier qu'on peut partager (demande de Jim, 09.11.2026) :
 * comptes rendus signés, visites, analyses validées, imagerie interprétée
 * (et ses clichés DICOM), rapports de bloc validés, rapports
 * d'hospitalisation signés, lettres de sortie signées, carnet de
 * vaccination, ordonnances, documents ajoutés au dossier. De toutes les
 * cliniques de l'organisation, comme le dossier.
 *
 * Chaque pièce se lit sous une seule forme imprimable (titre, sections,
 * tableau, fichiers), que la page du lien et l'export en PDF composent de
 * la même façon. Rien de ce qui n'est pas signé ou validé ne part.
 */
final class RecordShareItems
{
    /** Les groupes, dans l'ordre de la fenêtre, et les sortes qu'ils rangent */
    public const GROUPS = [
        'consultation' => ['label' => 'Consultations et comptes rendus', 'types' => ['consultation', 'visit']],
        'lab' => ['label' => 'Analyses', 'types' => ['lab']],
        'imaging' => ['label' => 'Imagerie', 'types' => ['imaging']],
        'surgery' => ['label' => 'Rapports de bloc', 'types' => ['surgery']],
        'stay_report' => ['label' => 'Rapports d’hospitalisation', 'types' => ['stay_report']],
        'discharge_letter' => ['label' => 'Lettres de sortie', 'types' => ['discharge_letter']],
        'vaccines' => ['label' => 'Carnet de vaccination', 'types' => ['vaccines']],
        'prescription' => ['label' => 'Ordonnances', 'types' => ['prescription']],
        'document' => ['label' => 'Documents ajoutés au dossier', 'types' => ['document']],
        'referral_letter' => ['label' => 'Lettres de référence', 'types' => ['referral_letter']],
    ];

    public const TYPES = ['consultation', 'visit', 'lab', 'imaging', 'surgery', 'stay_report', 'discharge_letter', 'vaccines', 'prescription', 'document', 'referral_letter'];

    private const APPOINTMENT_LABELS = [
        'consult' => 'Consultation', 'vaccin' => 'Vaccination', 'chir' => 'Chirurgie', 'ctrl' => 'Contrôle', 'imagerie' => 'Imagerie',
        'urgence' => 'Urgence', 'euth' => 'Fin de vie', 'tele' => 'Téléconsultation',
    ];

    /** @var array<int, Collection<int, int>> */
    private array $clinics = [];

    /** @var array<int, string> */
    private array $clinicNames = [];

    /**
     * Les pièces d'une période (dates locales, incluses ; null : sans borne), par groupe
     *
     * @return list<array{key: string, label: string, items: list<array{type: string, id: int, date: string, title: string, subtitle: string}>}>
     */
    public function candidates(Patient $patient, ?string $from, ?string $to, ?CarbonInterface $asOf = null): array
    {
        $all = collect([
            ...$this->consultations($patient), ...$this->visits($patient), ...$this->labs($patient), ...$this->imaging($patient),
            ...$this->surgeries($patient), ...$this->stayReports($patient), ...$this->dischargeLetters($patient),
            ...$this->vaccines($patient, $asOf), ...$this->prescriptions($patient), ...$this->documents($patient), ...$this->referralLetters($patient),
        ])->filter(fn (array $x) => ($from === null || $x['date'] >= $from) && ($to === null || $x['date'] <= $to));

        $out = [];
        foreach (self::GROUPS as $key => $group) {
            $items = $all->filter(fn (array $x) => in_array($x['type'], $group['types'], true))
                ->sortByDesc(fn (array $x) => $x['date'].sprintf('%010d', $x['id']))
                ->map(fn (array $x) => ['type' => $x['type'], 'id' => $x['id'], 'date' => $x['date'], 'title' => $x['title'], 'subtitle' => $x['subtitle']])
                ->values()->all();
            if ($items !== []) {
                $out[] = ['key' => $key, 'label' => $group['label'], 'items' => $items];
            }
        }

        return $out;
    }

    /**
     * Une pièce sous sa forme imprimable ; null si elle n'existe pas, n'est
     * pas de cet animal, ou n'est pas (encore) partageable. `visit_notes` :
     * les notes des visites partent aussi (au choix du vétérinaire) ;
     * `as_of` : ce qui s'ajoute ensuite (vaccin, complément d'un rapport de
     * bloc) ne rejoint pas un partage déjà fait.
     *
     * @param  array{visit_notes?: bool, as_of?: ?CarbonInterface}  $options
     * @return array<string, mixed>|null
     */
    public function printable(Patient $patient, string $type, int $id, array $options = []): ?array
    {
        $asOf = $options['as_of'] ?? null;

        return match ($type) {
            'consultation' => $this->consultationPrintable($patient, $id),
            'visit' => $this->visitPrintable($patient, $id, (bool) ($options['visit_notes'] ?? false)),
            'lab' => $this->labPrintable($patient, $id),
            'imaging' => $this->imagingPrintable($patient, $id),
            'surgery' => $this->surgeryPrintable($patient, $id, $asOf),
            'stay_report' => $this->stayReportPrintable($patient, $id),
            'discharge_letter' => $this->dischargeLetterPrintable($patient, $id),
            'vaccines' => $id === $patient->id ? $this->vaccinesPrintable($patient, $asOf) : null,
            'prescription' => $this->prescriptionPrintable($patient, $id),
            'document' => $this->documentPrintable($patient, $id),
            'referral_letter' => $this->referralLetterPrintable($patient, $id),
            default => null,
        };
    }

    /**
     * Un fichier d'une pièce (le document ajouté, un cliché DICOM) : chemin
     * sur le disque privé, nom et type ; null s'il n'existe pas
     *
     * @return array{path: string, name: string, mime: string}|null
     */
    public function file(Patient $patient, string $type, int $id, int $n): ?array
    {
        if ($type === 'document') {
            $document = $this->documentQuery($patient)->find($id);
            if (! $document || $n !== 0 || ! $document->chemin || ! Storage::disk('prive')->exists($document->chemin)) {
                return null;
            }

            return ['path' => $document->chemin, 'name' => $this->documentFileName($document), 'mime' => $this->mimeOf($document->chemin)];
        }
        if ($type === 'imaging') {
            $exam = $this->imagingQuery($patient)->find($id);
            $cliche = $exam?->cliches()->orderBy('id')->get()->values()->get($n);
            $chemin = $cliche instanceof ClicheDicom ? $cliche->cheminDeLOriginal($patient->organisation_id) : null;
            if ($chemin === null || ! Storage::disk('prive')->exists($chemin)) {
                return null;
            }

            return ['path' => $chemin, 'name' => ($n + 1).'-'.$cliche->sop_instance_uid.'.dcm', 'mime' => 'application/dicom'];
        }

        return null;
    }

    /** Le nombre de fichiers d'une pièce (clichés d'un examen, fichier d'un document) */
    public function fileCount(Patient $patient, string $type, int $id): int
    {
        return match ($type) {
            'imaging' => (int) $this->imagingQuery($patient)->whereKey($id)->withCount('cliches')->value('cliches_count'),
            'document' => 1,
            default => 0,
        };
    }

    /* ————— Les sortes ————— */

    /** @return Collection<int, int> */
    private function clinics(Patient $patient): Collection
    {
        return $this->clinics[$patient->organisation_id] ??= Clinique::withoutGlobalScopes()
            ->whereHas('societe', fn ($q) => $q->where('organisation_id', $patient->organisation_id))->pluck('id');
    }

    private function clinicName(?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        return $this->clinicNames[$id] ??= (string) Clinique::withoutGlobalScopes()->whereKey($id)->value('nom');
    }

    private function localDay(?CarbonInterface $at, ?int $clinicId = null): string
    {
        $zone = $clinicId ? (Clinique::withoutGlobalScopes()->whereKey($clinicId)->value('fuseau_horaire') ?: 'Europe/Zurich') : 'Europe/Zurich';

        return $at === null ? '' : CarbonImmutable::instance($at)->setTimezone($zone)->toDateString();
    }

    /** @return Builder<RendezVous> */
    private function consultationQuery(Patient $patient)
    {
        return RendezVous::withoutGlobalScopes()
            ->whereIn('clinique_id', $this->clinics($patient))
            ->where('patient_id', $patient->id)
            ->whereNot('status', StatutRendezVous::Annule->value)
            ->whereHas('compteRendu', fn ($q) => $q->whereNotNull('signe_le'))
            ->with(['compteRendu', 'lignePlanning' => fn ($q) => $q->withoutGlobalScopes()]);
    }

    /** @return list<array<string, mixed>> */
    private function consultations(Patient $patient): array
    {
        return $this->consultationQuery($patient)->get()->map(fn (RendezVous $r) => [
            'type' => 'consultation', 'id' => $r->id, 'date' => $this->localDay($r->starts_at, $r->clinique_id),
            'title' => $this->appointmentLabel($r).($r->reason ? ' : '.$r->reason : ''),
            'subtitle' => implode(' · ', array_filter(['Compte rendu', $r->lignePlanning?->nom, $this->clinicName($r->clinique_id)])),
        ])->all();
    }

    private function appointmentLabel(RendezVous $r): string
    {
        return self::APPOINTMENT_LABELS[$r->type->value] ?? 'Consultation';
    }

    /** @return array<string, mixed>|null */
    private function consultationPrintable(Patient $patient, int $id): ?array
    {
        $r = $this->consultationQuery($patient)->find($id);
        if (! $r) {
            return null;
        }
        $cr = $r->compteRendu;
        $sections = [];
        foreach (['s' => 'Motif et anamnèse', 'o' => 'Examen clinique', 'a' => 'Diagnostic', 'p' => 'Traitement et plan'] as $k => $label) {
            $text = trim((string) $cr?->{$k});
            if ($text !== '') {
                $sections[] = ['label' => $label, 'text' => $text];
            }
        }

        return $this->doc('consultation', $r->id, $this->localDay($r->starts_at, $r->clinique_id), $this->appointmentLabel($r).($r->reason ? ' : '.$r->reason : ''), $r->clinique_id, $r->lignePlanning?->nom, $sections);
    }

    /** @return list<array<string, mixed>> */
    private function visits(Patient $patient): array
    {
        return Visite::query()->where('patient_id', $patient->id)->get()->map(fn (Visite $v) => [
            'type' => 'visit', 'id' => $v->id, 'date' => $v->visite_le->toDateString(),
            'title' => 'Visite'.($v->motif ? ' : '.$v->motif : ''), 'subtitle' => 'Visite enregistrée',
        ])->all();
    }

    /** @return array<string, mixed>|null */
    private function visitPrintable(Patient $patient, int $id, bool $withNotes): ?array
    {
        $v = Visite::query()->where('patient_id', $patient->id)->find($id);
        if (! $v) {
            return null;
        }
        $sections = array_values(array_filter([
            $v->motif ? ['label' => 'Motif', 'text' => $v->motif] : null,
            $withNotes && $v->note ? ['label' => 'Notes', 'text' => $v->note] : null,
        ]));

        return $this->doc('visit', $v->id, $v->visite_le->toDateString(), 'Visite'.($v->motif ? ' : '.$v->motif : ''), null, null, $sections);
    }

    /** @return Builder<Analyse> */
    private function labQuery(Patient $patient)
    {
        return Analyse::withoutGlobalScopes()
            ->whereIn('clinique_id', $this->clinics($patient))
            ->where('patient_id', $patient->id)
            ->where('statut', StatutAnalyse::Valide->value);
    }

    /** @return list<array<string, mixed>> */
    private function labs(Patient $patient): array
    {
        return $this->labQuery($patient)->with('panels')->get()->map(fn (Analyse $a) => [
            'type' => 'lab', 'id' => $a->id, 'date' => $this->localDay($a->preleve_le ?? $a->created_at, $a->clinique_id),
            'title' => $this->labTitle($a), 'subtitle' => implode(' · ', array_filter(['Analyse '.$a->numero, $this->clinicName($a->clinique_id)])),
        ])->all();
    }

    private function labTitle(Analyse $a): string
    {
        $panels = $a->panels->pluck('nom')->filter()->implode(', ');

        return $panels !== '' ? $panels : 'Analyse';
    }

    /** @return array<string, mixed>|null */
    private function labPrintable(Patient $patient, int $id): ?array
    {
        $a = $this->labQuery($patient)->with(['panels', 'resultats', 'validePar' => fn ($q) => $q->withoutGlobalScopes()])->find($id);
        if (! $a) {
            return null;
        }
        $rows = $a->resultats->map(fn (AnalyseResultat $r) => [
            (string) $r->parametre,
            self::number($r->valeur),
            (string) $r->unite,
            self::number($r->borne_basse).' à '.self::number($r->borne_haute),
            match ($r->drapeau) {
                DrapeauResultatLabo::Haut => 'Haut',
                DrapeauResultatLabo::Bas => 'Bas',
                default => '',
            },
        ])->all();
        $sections = $a->note ? [['label' => 'Remarque', 'text' => (string) $a->note]] : [];

        return $this->doc('lab', $a->id, $this->localDay($a->preleve_le ?? $a->created_at, $a->clinique_id), $this->labTitle($a), $a->clinique_id, $a->validePar?->nom, $sections, [
            'columns' => ['Paramètre', 'Résultat', 'Unité', 'Normes', ''],
            'rows' => $rows,
        ], 'Analyse '.$a->numero);
    }

    /** @return Builder<ExamenImagerie> */
    private function imagingQuery(Patient $patient)
    {
        return ExamenImagerie::withoutGlobalScopes()
            ->whereIn('clinique_id', $this->clinics($patient))
            ->where('patient_id', $patient->id)
            ->whereNotNull('signe_le');
    }

    /** @return list<array<string, mixed>> */
    private function imaging(Patient $patient): array
    {
        return $this->imagingQuery($patient)->withCount('cliches')->get()->map(fn (ExamenImagerie $e) => [
            'type' => 'imaging', 'id' => $e->id, 'date' => $this->localDay($e->realise_le ?? $e->signe_le, $e->clinique_id),
            'title' => (string) ($e->libelle ?: 'Imagerie'),
            'subtitle' => implode(' · ', array_filter([$e->cliches_count ? $e->cliches_count.' '.($e->cliches_count > 1 ? 'clichés' : 'cliché') : 'Compte rendu', $this->clinicName($e->clinique_id)])),
        ])->all();
    }

    /** @return array<string, mixed>|null */
    private function imagingPrintable(Patient $patient, int $id): ?array
    {
        $e = $this->imagingQuery($patient)->with(['signePar' => fn ($q) => $q->withoutGlobalScopes(), 'cliches' => fn ($q) => $q->orderBy('id')])->find($id);
        if (! $e) {
            return null;
        }
        $sections = array_values(array_filter([
            $e->incidence ? ['label' => 'Incidence', 'text' => (string) $e->incidence] : null,
            $e->constatations ? ['label' => 'Constatations', 'text' => (string) $e->constatations] : null,
            $e->conclusion ? ['label' => 'Conclusion', 'text' => (string) $e->conclusion] : null,
        ]));
        $files = $e->cliches->values()->map(fn (ClicheDicom $c, int $n) => ['n' => $n, 'name' => ($n + 1).'-'.$c->sop_instance_uid.'.dcm', 'mime' => 'application/dicom', 'size' => (int) $c->taille_octets])->all();

        return $this->doc('imaging', $e->id, $this->localDay($e->realise_le ?? $e->signe_le, $e->clinique_id), (string) ($e->libelle ?: 'Imagerie'), $e->clinique_id, $e->signePar?->nom, $sections, null, null, $files);
    }

    /** @return Builder<SurgeryReport> */
    private function surgeryQuery(Patient $patient)
    {
        return SurgeryReport::query()
            ->where('status', 'validated')
            ->whereHas('intervention', fn ($q) => $q->withoutGlobalScopes()->where('patient_id', $patient->id)->whereIn('clinique_id', $this->clinics($patient)))
            ->with(['intervention' => fn ($q) => $q->withoutGlobalScopes(), 'complements']);
    }

    /** @return list<array<string, mixed>> */
    private function surgeries(Patient $patient): array
    {
        return $this->surgeryQuery($patient)->get()->map(fn (SurgeryReport $s) => [
            'type' => 'surgery', 'id' => $s->id, 'date' => $this->localDay($s->intervention?->prevu_le, $s->intervention?->clinique_id),
            'title' => (string) ($s->intervention->procedure ?? 'Intervention'),
            'subtitle' => implode(' · ', array_filter(['Rapport de bloc', $s->validated_by_name, $this->clinicName($s->intervention?->clinique_id)])),
        ])->all();
    }

    /** @return array<string, mixed>|null */
    private function surgeryPrintable(Patient $patient, int $id, ?CarbonInterface $asOf = null): ?array
    {
        $s = $this->surgeryQuery($patient)->find($id);
        if (! $s) {
            return null;
        }
        $sections = [];
        foreach (SurgeryReport::normalizeSections($s->sections) as $k => $text) {
            if ($text !== '') {
                $sections[] = ['label' => SurgeryReport::SECTION_LABELS[$k], 'text' => $text];
            }
        }
        foreach ($s->complements->filter(fn ($c) => $asOf === null || $c->created_at <= $asOf) as $c) {
            $sections[] = ['label' => 'Complément, '.$c->by_name.', '.$c->created_at?->format('d.m.Y'), 'text' => (string) $c->text];
        }
        $intervention = $s->intervention;

        return $this->doc('surgery', $s->id, $this->localDay($intervention?->prevu_le, $intervention?->clinique_id), (string) ($intervention->procedure ?? 'Intervention'), $intervention?->clinique_id, $s->validated_by_name, $sections, null, 'Rapport de bloc');
    }

    /** @return Collection<int, Sejour> */
    private function stays(Patient $patient): Collection
    {
        return Sejour::withoutGlobalScopes()->where('patient_id', $patient->id)->whereIn('clinique_id', $this->clinics($patient))->get();
    }

    /** @return list<array<string, mixed>> */
    private function stayReports(Patient $patient): array
    {
        $stays = $this->stays($patient)->keyBy('id');

        return StaySheet::query()->whereIn('sejour_id', $stays->keys())->where('report_status', StaySheet::REPORT_SIGNED)->get()
            ->map(fn (StaySheet $sheet) => [
                'type' => 'stay_report', 'id' => $sheet->id, 'date' => $sheet->day->toDateString(),
                'title' => 'Rapport d’hospitalisation du '.$this->longDay($sheet->day->toDateString()),
                'subtitle' => implode(' · ', array_filter([$sheet->report_signed_by_name, $this->clinicName($stays[$sheet->sejour_id]->clinique_id ?? null)])),
            ])->all();
    }

    /** @return array<string, mixed>|null */
    private function stayReportPrintable(Patient $patient, int $id): ?array
    {
        $stays = $this->stays($patient)->keyBy('id');
        $sheet = StaySheet::query()->whereIn('sejour_id', $stays->keys())->where('report_status', StaySheet::REPORT_SIGNED)->find($id);
        if (! $sheet) {
            return null;
        }
        $stay = $stays[$sheet->sejour_id];

        return $this->doc('stay_report', $sheet->id, $sheet->day->toDateString(), 'Rapport d’hospitalisation du '.$this->longDay($sheet->day->toDateString()), $stay->clinique_id, $sheet->report_signed_by_name, [
            ['label' => 'Motif du séjour', 'text' => (string) $stay->motif],
            ['label' => 'Rapport de la journée', 'text' => (string) $sheet->report_text],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function dischargeLetters(Patient $patient): array
    {
        $stays = $this->stays($patient)->keyBy('id');

        return DischargeLetter::query()->whereIn('sejour_id', $stays->keys())->where('status', 'signed')->get()
            ->map(fn (DischargeLetter $l) => [
                'type' => 'discharge_letter', 'id' => $l->id, 'date' => $this->localDay($stays[$l->sejour_id]->sorti_le ?? $l->signed_at, $stays[$l->sejour_id]->clinique_id),
                'title' => 'Lettre de sortie d’hospitalisation',
                'subtitle' => implode(' · ', array_filter([$l->signed_by_name, $this->clinicName($stays[$l->sejour_id]->clinique_id)])),
            ])->all();
    }

    /** @return array<string, mixed>|null */
    private function dischargeLetterPrintable(Patient $patient, int $id): ?array
    {
        $stays = $this->stays($patient)->keyBy('id');
        $letter = DischargeLetter::query()->whereIn('sejour_id', $stays->keys())->where('status', 'signed')->find($id);
        if (! $letter) {
            return null;
        }
        $stay = $stays[$letter->sejour_id];
        $sections = array_map(fn (array $b) => ['label' => $b['title'], 'text' => implode("\n", $b['lines'])], app(DischargeLetters::class)->blocks($stay, $letter));

        return $this->doc('discharge_letter', $letter->id, $this->localDay($stay->sorti_le ?? $letter->signed_at, $stay->clinique_id), 'Lettre de sortie d’hospitalisation', $stay->clinique_id, $letter->signed_by_name, $sections);
    }

    /**
     * Le carnet : une seule pièce ; `as_of` le fige au moment du partage
     *
     * @return list<array<string, mixed>>
     */
    private function vaccines(Patient $patient, ?CarbonInterface $asOf = null): array
    {
        $query = fn () => Vaccination::query()->where('patient_id', $patient->id)->when($asOf, fn ($q) => $q->where('created_at', '<=', $asOf));
        $last = $query()->max('injectee_le');
        if ($last === null) {
            return [];
        }
        $count = $query()->count();

        return [[
            'type' => 'vaccines', 'id' => $patient->id, 'date' => CarbonImmutable::parse($last)->toDateString(),
            'title' => 'Carnet de vaccination', 'subtitle' => $count.' '.($count > 1 ? 'vaccinations' : 'vaccination'),
        ]];
    }

    /** @return array<string, mixed> */
    private function vaccinesPrintable(Patient $patient, ?CarbonInterface $asOf = null): ?array
    {
        $vaccinations = Vaccination::query()->where('patient_id', $patient->id)->when($asOf, fn ($q) => $q->where('created_at', '<=', $asOf))->orderByDesc('injectee_le')->get();
        $rows = $vaccinations->map(fn (Vaccination $v) => [
            $v->injectee_le->format('d.m.Y'),
            trim($v->nom.($v->marque ? ', '.$v->marque : '')),
            (string) $v->lot,
            $v->echeance_le?->format('d.m.Y') ?? '',
        ])->all();
        if ($rows === []) {
            return null;
        }
        $last = $vaccinations->max('injectee_le');

        return $this->doc('vaccines', $patient->id, CarbonImmutable::parse($last)->toDateString(), 'Carnet de vaccination', null, null, [], [
            'columns' => ['Injecté le', 'Vaccin', 'Lot', 'Rappel le'],
            'rows' => $rows,
        ]);
    }

    /** @return Builder<Ordonnance> */
    private function prescriptionQuery(Patient $patient)
    {
        return Ordonnance::withoutGlobalScopes()
            ->where('organisation_id', $patient->organisation_id)
            ->where('patient_id', $patient->id);
    }

    /** @return list<array<string, mixed>> */
    private function prescriptions(Patient $patient): array
    {
        return $this->prescriptionQuery($patient)->with(['lignes.produit' => fn ($q) => $q->withoutGlobalScopes()])->get()->map(fn (Ordonnance $o) => [
            'type' => 'prescription', 'id' => $o->id, 'date' => $o->signee_le->toDateString(),
            'title' => 'Ordonnance '.$o->numero,
            'subtitle' => $o->lignes->map(fn (OrdonnanceLigne $l) => $l->produit->nom ?? '')->filter()->implode(', '),
        ])->all();
    }

    /** @return array<string, mixed>|null */
    private function prescriptionPrintable(Patient $patient, int $id): ?array
    {
        $o = $this->prescriptionQuery($patient)->with(['lignes.produit' => fn ($q) => $q->withoutGlobalScopes(), 'employe' => fn ($q) => $q->withoutGlobalScopes()])->find($id);
        if (! $o) {
            return null;
        }
        $rows = $o->lignes->map(fn (OrdonnanceLigne $l) => [(string) ($l->produit->nom ?? 'Médicament'), (string) $l->quantite, (string) $l->posologie, (string) $l->duree])->all();
        $sections = $o->note ? [['label' => 'Remarque', 'text' => (string) $o->note]] : [];

        return $this->doc('prescription', $o->id, $o->signee_le->toDateString(), 'Ordonnance '.$o->numero, $o->clinique_id, $o->employe?->nom, $sections, [
            'columns' => ['Médicament', 'Quantité', 'Posologie', 'Durée'],
            'rows' => $rows,
        ]);
    }

    /** @return Builder<ReferralLetter> */
    private function referralQuery(Patient $patient)
    {
        return ReferralLetter::withoutGlobalScopes()
            ->where('organisation_id', $patient->organisation_id)
            ->where('patient_id', $patient->id)
            ->where('status', 'signed');
    }

    private function referralTitle(ReferralLetter $l): string
    {
        return 'Lettre de référence'.($l->recipient_name ? ' à '.$l->recipient_name : '');
    }

    /** @return list<array<string, mixed>> */
    private function referralLetters(Patient $patient): array
    {
        return $this->referralQuery($patient)->get()->map(fn (ReferralLetter $l) => [
            'type' => 'referral_letter', 'id' => $l->id, 'date' => $this->localDay($l->signed_at, $l->clinique_id),
            'title' => $this->referralTitle($l), 'subtitle' => implode(' · ', array_filter([$l->signed_by_name, $this->clinicName($l->clinique_id)])),
        ])->all();
    }

    /** @return array<string, mixed>|null */
    private function referralLetterPrintable(Patient $patient, int $id): ?array
    {
        $letter = $this->referralQuery($patient)->find($id);
        if (! $letter) {
            return null;
        }
        $sections = array_map(fn (array $b) => ['label' => $b['title'], 'text' => implode("\n", $b['lines'])], app(ReferralLetters::class)->blocks($letter));

        return $this->doc('referral_letter', $letter->id, $this->localDay($letter->signed_at, $letter->clinique_id), $this->referralTitle($letter), $letter->clinique_id, $letter->signed_by_name, $sections);
    }

    /** @return HasMany<Document, Patient> */
    private function documentQuery(Patient $patient)
    {
        return $patient->documents();
    }

    /** @return list<array<string, mixed>> */
    private function documents(Patient $patient): array
    {
        // La lettre de référence rangée en document : déjà parmi les lettres de référence, jamais deux fois
        return $this->documentQuery($patient)->whereNotNull('chemin')->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'lettre_reference'))->get()->map(fn (Document $d) => [
            'type' => 'document', 'id' => $d->id, 'date' => $d->etabli_le->toDateString(),
            'title' => (string) $d->nom, 'subtitle' => (string) ($d->type ?: 'Document'),
        ])->all();
    }

    /** @return array<string, mixed>|null */
    private function documentPrintable(Patient $patient, int $id): ?array
    {
        $d = $this->documentQuery($patient)->whereNotNull('chemin')->find($id);
        if (! $d) {
            return null;
        }
        $file = ['n' => 0, 'name' => $this->documentFileName($d), 'mime' => $this->mimeOf((string) $d->chemin), 'size' => (int) $d->taille_octets];

        return $this->doc('document', $d->id, $d->etabli_le->toDateString(), (string) $d->nom, null, $d->signataire, [], null, (string) ($d->type ?: 'Document'), [$file]);
    }

    private function documentFileName(Document $d): string
    {
        $extension = pathinfo((string) $d->chemin, PATHINFO_EXTENSION);

        return Str::slug((string) $d->nom).($extension ? '.'.$extension : '');
    }

    private function mimeOf(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'heic' => 'image/heic',
            default => 'application/octet-stream',
        };
    }

    /** « 112 », « 0,5 » : sans zéros inutiles, virgule décimale */
    private static function number(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $text = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');

        return str_replace('.', ',', $text);
    }

    private function longDay(string $date): string
    {
        return CarbonImmutable::parse($date)->locale('fr_CH')->isoFormat('D MMMM YYYY');
    }

    /**
     * La forme imprimable commune
     *
     * @param  list<array{label: string, text: string}>  $sections
     * @param  array{columns: list<string>, rows: list<list<string>>}|null  $table
     * @param  list<array{n: int, name: string, mime: string, size: int}>  $files
     * @return array<string, mixed>
     */
    private function doc(string $type, int $id, string $date, string $title, ?int $clinicId, ?string $author, array $sections, ?array $table = null, ?string $kind = null, array $files = []): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'date' => $date,
            'title' => $title,
            'kind' => $kind ?? self::GROUPS[$this->groupOf($type)]['label'],
            'clinic' => $this->clinicName($clinicId),
            'author' => $author,
            'sections' => array_values(array_filter($sections, fn (array $s) => trim($s['text']) !== '')),
            'table' => $table,
            'files' => $files,
        ];
    }

    private function groupOf(string $type): string
    {
        foreach (self::GROUPS as $key => $group) {
            if (in_array($type, $group['types'], true)) {
                return $key;
            }
        }

        return 'document';
    }
}
