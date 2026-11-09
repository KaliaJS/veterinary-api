<?php

namespace App\Services\Referrals;

use App\Enums\StatutOrdonnance;
use App\Jobs\WriteReferralLetter;
use App\Models\Clinique;
use App\Models\Document;
use App\Models\Ordonnance;
use App\Models\OrdonnanceLigne;
use App\Models\Patient;
use App\Models\Pesee;
use App\Models\ReferralLetter;
use App\Services\Bloc\SurgeryReportDrafts;
use App\Services\BudgetAnthropic;
use App\Services\CompteurMensuel;
use App\Services\Patients\RecordShareItems;
use App\Support\Pdf\SimplePdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * La lettre de référence (choix de Jim, 09.11.2026) : la demande du
 * brouillon (bornée par le plafond de rédactions et le budget de l'IA de la
 * plateforme), le nettoyage de la réponse (une phrase ne garde que les
 * sources qui existent ; sans source, elle est retirée), ce qui est recopié
 * sans passer par l'IA (l'identité de l'animal, son traitement en cours, la
 * liste des pièces jointes), la lettre telle que le confrère la lit, et son
 * PDF rangé au dossier à la signature. Jamais de tiret de ponctuation.
 */
final class ReferralLetters
{
    /** L'ordre de la lettre : les rubriques de l'IA et ce qui est recopié */
    public const ORDER = ['patient', 'reason', 'history', 'exams', 'treatment', 'request', 'attachments'];

    public const COPIED_LABELS = [
        'patient' => 'Patient',
        'treatment' => 'Traitement en cours',
        'attachments' => 'Pièces jointes',
    ];

    /** Une ordonnance active de ces derniers jours fait le traitement en cours */
    private const TREATMENT_DAYS = 60;

    public function __construct(private RecordShareItems $items) {}

    /**
     * Demande un brouillon ; rend la raison d'un refus ('signed', 'writing',
     * 'writings', 'budget'), ou null quand la rédaction part.
     */
    public function request(ReferralLetter $letter, bool $isDictation = false, int $delaySeconds = 0): ?string
    {
        $refusal = DB::transaction(function () use ($letter, $isDictation): ?string {
            $locked = ReferralLetter::withoutGlobalScopes()->whereKey($letter->id)->lockForUpdate()->firstOrFail();
            $refusal = match (true) {
                $locked->isSigned() => 'signed',
                $locked->status === 'writing' => 'writing',
                $locked->writings >= (int) config('transcription.redactions_max') => 'writings',
                app(BudgetAnthropic::class)->atteint() => 'budget',
                default => null,
            };
            if ($refusal === 'writings' || $refusal === 'budget') {
                $locked->forceFill(['error' => $refusal])->save();
            }
            if ($refusal === 'writing' && $isDictation) {
                $locked->forceFill(['is_redraft_pending' => true])->save();
            }
            if ($refusal !== null) {
                return $refusal;
            }
            $locked->forceFill(['status' => 'writing', 'error' => null, 'writings' => $locked->writings + 1, 'is_redraft_pending' => false])->save();

            return null;
        });
        if ($refusal !== null) {
            if ($refusal === 'writings' || $refusal === 'budget') {
                ReferralLetter::announce($letter->id, $letter->clinique_id);
            }

            return $refusal;
        }

        CompteurMensuel::ajouter('anthropic:redactions', 1);
        try {
            WriteReferralLetter::dispatch($letter->id)->delay($delaySeconds > 0 ? now()->addSeconds($delaySeconds) : null);
        } catch (Throwable $error) {
            // La file d'attente refuse la tâche : la lettre ne reste pas « en rédaction » pour toujours
            ReferralLetter::withoutGlobalScopes()->whereKey($letter->id)->where('status', 'writing')->update(['status' => 'failed', 'error' => 'writer']);
            ReferralLetter::announce($letter->id, $letter->clinique_id);

            throw $error;
        }
        ReferralLetter::announce($letter->id, $letter->clinique_id);

        return null;
    }

    /**
     * Les rubriques après un nouveau brouillon : une rubrique corrigée garde
     * son texte, même vidée exprès ; les autres prennent le nouveau texte.
     *
     * @param  array<string, mixed>|null  $current
     * @param  list<string>|null  $edited
     * @param  array<string, list<array{text: string, sources: list<string>}>>  $newSections
     * @return array<string, string>
     */
    public static function merge(?array $current, ?array $edited, array $newSections): array
    {
        $current = ReferralLetter::normalizeSections($current);
        $out = [];
        foreach (ReferralLetter::SECTIONS as $key) {
            $out[$key] = in_array($key, $edited ?? [], true) ? $current[$key] : SurgeryReportDrafts::text($newSections[$key] ?? []);
        }

        return $out;
    }

    /**
     * @param  array{sections?: mixed, doubts?: mixed}  $raw
     * @param  list<array{id: string, kind: string, label: string, text: string}>  $sources
     * @return array{sources: list<array{id: string, kind: string, label: string, text: string}>, sections: array<string, list<array{text: string, sources: list<string>}>>, doubts: list<array{section: string, passage: string, heard: string, reason: string}>}
     */
    public function clean(array $raw, array $sources): array
    {
        $known = array_column($sources, 'id');
        $sections = [];
        foreach (ReferralLetter::SECTIONS as $key) {
            $sections[$key] = collect(is_array($raw['sections'][$key] ?? null) ? $raw['sections'][$key] : [])
                ->map(fn ($line) => [
                    'text' => SurgeryReportDrafts::undash(trim((string) ($line['text'] ?? ''))),
                    'sources' => array_values(array_intersect(array_map('strval', (array) ($line['sources'] ?? [])), $known)),
                ])
                ->filter(fn (array $line) => $line['text'] !== '' && $line['sources'] !== [])
                ->values()
                ->all();
        }
        $doubts = collect(is_array($raw['doubts'] ?? null) ? $raw['doubts'] : [])
            ->map(fn ($d) => [
                'section' => (string) ($d['section'] ?? ''),
                'passage' => SurgeryReportDrafts::undash(trim((string) ($d['passage'] ?? ''))),
                'heard' => SurgeryReportDrafts::undash(trim((string) ($d['heard'] ?? ''))),
                'reason' => SurgeryReportDrafts::undash(trim((string) ($d['reason'] ?? ''))),
            ])
            ->filter(fn (array $d) => array_key_exists($d['section'], $sections) && $d['reason'] !== ''
                && ($d['passage'] === '' || str_contains(SurgeryReportDrafts::text($sections[$d['section']]), $d['passage'])))
            ->values()
            ->all();

        return ['sources' => $sources, 'sections' => $sections, 'doubts' => $doubts];
    }

    /**
     * Ce qui est recopié tel quel, jamais par l'IA : l'animal, son
     * propriétaire, son traitement en cours (ordonnances actives des 60
     * derniers jours), les pièces jointes, la clinique qui réfère. Figé à la
     * signature.
     *
     * @return array{patient: list<string>, treatment: list<string>, attachments: list<string>, clinic: array{name: string, phone: ?string, address: string}}
     */
    public function copied(ReferralLetter $letter): array
    {
        $patient = Patient::withoutGlobalScopes()->with('client')->findOrFail($letter->patient_id);
        $born = $patient->ne_le;
        $age = $born ? ((int) $born->diffInMonths(now()) < 24 ? (int) $born->diffInMonths(now()).' mois' : intdiv((int) $born->diffInMonths(now()), 12).' ans') : null;
        $weight = Pesee::query()->where('patient_id', $patient->id)->latest('pesee_le')->value('poids_kg');
        $identity = implode(', ', array_filter([
            $patient->nom,
            $patient->espece->libelle(),
            $patient->race,
            $patient->sexe->value.($patient->est_sterilise ? ($patient->sexe->value === 'Femelle' ? ' stérilisée' : ' castré') : ''),
            $age,
            $weight !== null ? str_replace('.', ',', rtrim(rtrim(number_format((float) $weight, 2, '.', ''), '0'), '.')).' kg' : null,
        ]));
        $owner = $patient->client;
        $lines = array_values(array_filter([
            $identity,
            $patient->puce ? 'Puce '.$patient->puce : null,
            $owner ? 'Propriétaire : '.implode(', ', array_filter([$owner->fullName(), $owner->tel])) : null,
        ]));

        $treatment = Ordonnance::withoutGlobalScopes()
            ->where('organisation_id', $patient->organisation_id)
            ->where('patient_id', $patient->id)
            ->where('statut', StatutOrdonnance::Active->value)
            ->where('signee_le', '>=', now()->subDays(self::TREATMENT_DAYS))
            ->with(['lignes.produit' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderByDesc('signee_le')
            ->get()
            ->flatMap(fn (Ordonnance $o) => $o->lignes->map(fn (OrdonnanceLigne $l) => trim(($l->produit->nom ?? 'Médicament').' : '.implode(', ', array_filter([$l->posologie, $l->duree])).', depuis le '.$o->signee_le->format('d.m.Y'))))
            ->values()->all();

        // Les pièces jointes, d'après la liste du dossier : une lecture pour toutes, pas une par pièce
        $attachments = [];
        if (! empty($letter->items)) {
            $known = collect($this->items->candidates($patient, null, null))->flatMap(fn (array $g) => $g['items'])->keyBy(fn (array $i) => $i['type'].':'.$i['id']);
            foreach ($letter->items as $item) {
                $p = $known->get($item['type'].':'.$item['id']);
                if ($p !== null) {
                    $attachments[] = $p['title'].($p['date'] ? ', '.CarbonImmutable::parse($p['date'])->format('d.m.Y') : '');
                }
            }
        }

        $clinique = Clinique::withoutGlobalScopes()->find($letter->clinique_id);
        $header = $clinique?->enTeteDocument() ?? ['nom' => '', 'adresse' => '', 'contact' => ''];

        return [
            'patient' => $lines,
            'treatment' => $treatment,
            'attachments' => $attachments,
            'clinic' => ['name' => (string) $header['nom'], 'phone' => $clinique?->tel, 'address' => (string) $header['adresse']],
        ];
    }

    /**
     * La lettre telle que le confrère la lit : rubriques dans l'ordre, les vides laissées de côté
     *
     * @return list<array{key: string, title: string, lines: list<string>}>
     */
    public function blocks(ReferralLetter $letter, ?array $copied = null): array
    {
        $sections = ReferralLetter::normalizeSections($letter->sections);
        $copied ??= $letter->isSigned() && is_array($letter->copied) ? $letter->copied : $this->copied($letter);
        $out = [];
        foreach (self::ORDER as $key) {
            $lines = match ($key) {
                'patient' => $copied['patient'] ?? [],
                'treatment' => $copied['treatment'] ?? [],
                'attachments' => $copied['attachments'] ?? [],
                default => array_values(array_filter(array_map('trim', preg_split('/\R/', $sections[$key]) ?: []), fn ($l) => $l !== '')),
            };
            if ($lines !== []) {
                $out[] = ['key' => $key, 'title' => ReferralLetter::SECTION_LABELS[$key] ?? self::COPIED_LABELS[$key], 'lines' => $lines];
            }
        }

        return $out;
    }

    /** La lettre signée en PDF, comme les autres pièces de la clinique */
    public function pdf(ReferralLetter $letter): string
    {
        $clinique = Clinique::withoutGlobalScopes()->with('societe')->findOrFail($letter->clinique_id);
        $header = $clinique->enTeteDocument();
        $zone = $clinique->fuseau_horaire ?: 'Europe/Zurich';
        $pdf = new SimplePdf(implode(' · ', array_filter([$header['societe'] ?: $header['nom'], $header['ide'] ? 'IDE '.$header['ide'] : null])));
        $pdf->line($header['nom'], 13, true, gap: 3);
        foreach (array_filter([$header['adresse'], $header['contact']]) as $info) {
            $pdf->line($info, 9, isGray: true, gap: 2);
        }
        $to = implode(', ', array_filter([$letter->recipient_name, $letter->recipient_clinic]));
        $pdf->space(22)
            ->line('Lettre de référence', 18, true, gap: 6)
            ->line($to !== '' ? 'À l’attention de '.$to : 'À l’attention du confrère', 11, gap: 3)
            ->line('Date : '.($letter->signed_at ?? now())->copy()->setTimezone($zone)->format('d.m.Y'), 11, gap: 14);
        foreach ($this->blocks($letter) as $block) {
            $pdf->line(mb_strtoupper($block['title']), 8, true, isGray: true, gap: 4);
            foreach ($block['lines'] as $line) {
                $pdf->row([$line], [SimplePdf::RIGHT - SimplePdf::LEFT], size: 10.5, gap: 3);
            }
            $pdf->space(8);
        }
        if ($letter->signed_by_name) {
            $pdf->space(10)->line('Avec nos remerciements, '.$letter->signed_by_name, 10.5, gap: 4);
        }

        return $pdf->output();
    }

    /** La lettre signée, rangée dans les documents de l'animal */
    public function file(ReferralLetter $letter): Document
    {
        $patient = Patient::withoutGlobalScopes()->findOrFail($letter->patient_id);
        $bytes = $this->pdf($letter);
        $path = 'documents/'.$patient->organisation_id.'/'.Str::uuid().'.pdf';
        Storage::disk('prive')->put($path, $bytes);
        $name = 'Lettre de référence'.($letter->recipient_name ? ' : '.$letter->recipient_name : '');

        return $patient->documents()->create([
            'nom' => mb_substr($name, 0, 255),
            'type' => 'lettre_reference',
            'chemin' => $path,
            'nom_fichier' => Str::slug($name).'.pdf',
            'taille_octets' => strlen($bytes),
            'visible_proprietaire' => false,
            'signataire' => $letter->signed_by_name,
            'employe_id' => $letter->signed_by_id,
            'etabli_le' => ($letter->signed_at ?? now())->copy()->setTimezone(Clinique::withoutGlobalScopes()->find($letter->clinique_id)?->fuseau_horaire ?: 'Europe/Zurich')->toDateString(),
        ]);
    }
}
