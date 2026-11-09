<?php

namespace App\Services\Hospitalisation;

use App\Enums\StatutOrdonnance;
use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Jobs\WriteDischargeLetter;
use App\Models\Clinique;
use App\Models\DischargeLetter;
use App\Models\Ordonnance;
use App\Models\OrdonnanceLigne;
use App\Models\RendezVous;
use App\Models\Sejour;
use App\Services\Bloc\SurgeryReportDrafts;
use App\Services\BudgetAnthropic;
use App\Services\CompteurMensuel;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * La lettre de sortie (choix de Jim, 09.11.2026) : la demande du brouillon
 * (bornée par le plafond de rédactions et le budget de l'IA de la
 * plateforme, comme le rapport de bloc), le nettoyage de la réponse du
 * rédacteur (une phrase ne garde que les sources qui existent ; sans
 * source, elle est retirée), et ce qui est recopié sans passer par l'IA :
 * les médicaments de l'ordonnance signée pendant le séjour, le prochain
 * rendez-vous de l'animal au planning, la clinique. Jamais de tiret de
 * ponctuation (règle de Jim).
 */
final class DischargeLetters
{
    /** L'ordre de la lettre : les rubriques de l'IA et ce qui est recopié */
    public const ORDER = ['stay', 'during', 'medicines', 'home', 'follow_up', 'call'];

    public const COPIED_LABELS = [
        'medicines' => 'Ses médicaments',
        'follow_up' => 'Son contrôle',
    ];

    public function __construct(private StaySheets $sheets) {}

    /**
     * Demande un brouillon ; rend la raison d'un refus ('signed', 'writing',
     * 'writings', 'budget'), ou null quand la rédaction part.
     */
    public function request(Sejour $sejour, bool $isDictation = false, int $delaySeconds = 0): ?string
    {
        $refusal = DB::transaction(function () use ($sejour, $isDictation): ?string {
            self::ensure($sejour);
            $letter = DischargeLetter::query()->where('sejour_id', $sejour->id)->lockForUpdate()->firstOrFail();
            $refusal = match (true) {
                $letter->isSigned() => 'signed',
                $letter->status === 'writing' => 'writing',
                $letter->writings >= (int) config('transcription.redactions_max') => 'writings',
                app(BudgetAnthropic::class)->atteint() => 'budget',
                default => null,
            };
            if ($refusal === 'writings' || $refusal === 'budget') {
                $letter->forceFill(['error' => $refusal])->save();
            }
            // Une dictée prête pendant une rédaction : un nouveau brouillon suit celle en cours
            if ($refusal === 'writing' && $isDictation) {
                $letter->forceFill(['is_redraft_pending' => true])->save();
            }
            if ($refusal !== null) {
                return $refusal;
            }
            $letter->forceFill(['status' => 'writing', 'error' => null, 'writings' => $letter->writings + 1, 'is_redraft_pending' => false])->save();

            return null;
        });
        if ($refusal !== null) {
            if ($refusal === 'writings' || $refusal === 'budget') {
                Sejour::announce($sejour->id, $sejour->clinique_id);
            }

            return $refusal;
        }

        // Comptée au budget du mois où elle part, comme les comptes rendus
        CompteurMensuel::ajouter('anthropic:redactions', 1);
        WriteDischargeLetter::dispatch($sejour->id)->delay($delaySeconds > 0 ? now()->addSeconds($delaySeconds) : null);
        Sejour::announce($sejour->id, $sejour->clinique_id);

        return null;
    }

    /** La lettre existe (deux premières demandes en même temps : une seule ligne) */
    public static function ensure(Sejour $sejour): void
    {
        try {
            DischargeLetter::query()->firstOrCreate(['sejour_id' => $sejour->id]);
        } catch (UniqueConstraintViolationException) {
            // Créée à l'instant par une autre requête
        }
    }

    /**
     * Les rubriques après un nouveau brouillon : une rubrique corrigée par le
     * vétérinaire garde son texte, même vidée exprès ; seules celles qu'il
     * n'a jamais touchées prennent le nouveau texte.
     *
     * @param  array<string, mixed>|null  $current
     * @param  list<string>|null  $edited
     * @param  array<string, list<array{text: string, sources: list<string>}>>  $newSections
     * @return array<string, string>
     */
    public static function merge(?array $current, ?array $edited, array $newSections): array
    {
        $current = DischargeLetter::normalizeSections($current);
        $out = [];
        foreach (DischargeLetter::SECTIONS as $key) {
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
        foreach (DischargeLetter::SECTIONS as $key) {
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
            ->filter(function (array $d) use ($sections): bool {
                if (! array_key_exists($d['section'], $sections) || $d['reason'] === '') {
                    return false;
                }

                return $d['passage'] === '' || str_contains(SurgeryReportDrafts::text($sections[$d['section']]), $d['passage']);
            })
            ->values()
            ->all();

        return ['sources' => $sources, 'sections' => $sections, 'doubts' => $doubts];
    }

    /**
     * Ce qui est recopié tel quel, jamais par l'IA : les médicaments des
     * ordonnances actives signées pendant le séjour, le prochain contrôle de
     * l'animal dans la clinique après la sortie (ou maintenant), et la
     * clinique. Figé dans la lettre à la signature, qui suit la sortie.
     *
     * @return array{medicines: list<array{product: string, posology: string, duration: string, prescription: string}>, follow_up: ?array{at: string, label: string, with: ?string}, clinic: array{name: string, phone: ?string}}
     */
    public function copied(Sejour $sejour): array
    {
        $tz = $this->sheets->timezone($sejour);
        $from = CarbonImmutable::instance($sejour->admis_le)->setTimezone($tz)->toDateString();
        $to = CarbonImmutable::instance($sejour->sorti_le ?? now())->setTimezone($tz)->toDateString();

        $medicines = [];
        $prescriptions = Ordonnance::withoutGlobalScopes()
            ->where('clinique_id', $sejour->clinique_id)
            ->where('patient_id', $sejour->patient_id)
            ->where('statut', StatutOrdonnance::Active->value)
            ->whereDate('signee_le', '>=', $from)
            ->whereDate('signee_le', '<=', $to)
            // Le jour de l'admission, seulement celles faites après elle (pas celle de la consultation du matin)
            ->where('created_at', '>=', $sejour->admis_le)
            ->with(['lignes.produit' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderBy('signee_le')
            ->orderBy('id')
            ->get();
        foreach ($prescriptions as $prescription) {
            foreach ($prescription->lignes as $line) {
                /** @var OrdonnanceLigne $line */
                $medicines[] = [
                    'product' => (string) ($line->produit->nom ?? 'Médicament'),
                    'posology' => trim((string) $line->posologie),
                    'duration' => trim((string) $line->duree),
                    'prescription' => (string) $prescription->numero,
                ];
            }
        }

        $next = RendezVous::withoutGlobalScopes()
            ->where('clinique_id', $sejour->clinique_id)
            ->where('patient_id', $sejour->patient_id)
            ->where('type', TypeRendezVous::Controle->value)
            // À venir : après la sortie et après maintenant (une lettre signée plus tard n'annonce pas un contrôle passé)
            ->where('starts_at', '>', max($sejour->sorti_le ?? now(), now()))
            ->whereNotIn('status', [StatutRendezVous::Annule->value, StatutRendezVous::Noshow->value, StatutRendezVous::Fini->value])
            ->with(['lignePlanning' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderBy('starts_at')
            ->first();
        $followUp = null;
        if ($next !== null) {
            $at = CarbonImmutable::instance($next->starts_at)->setTimezone($tz)->locale('fr_CH');
            $followUp = [
                'at' => $next->starts_at->toIso8601String(),
                'label' => ucfirst($at->isoFormat('dddd D MMMM')).' à '.$at->format('H:i'),
                'with' => $next->lignePlanning?->nom,
            ];
        }

        $clinique = Clinique::withoutGlobalScopes()->find($sejour->clinique_id);

        return [
            'medicines' => $medicines,
            'follow_up' => $followUp,
            'clinic' => ['name' => (string) $clinique?->nom, 'phone' => $clinique?->tel],
        ];
    }

    /**
     * La lettre telle que le propriétaire la lit : rubriques dans l'ordre,
     * les vides laissées de côté
     *
     * @return list<array{key: string, title: string, lines: list<string>}>
     */
    public function blocks(Sejour $sejour, DischargeLetter $letter): array
    {
        $sections = DischargeLetter::normalizeSections($letter->sections);
        $copied = $letter->isSigned() && is_array($letter->copied) ? $letter->copied : $this->copied($sejour);
        $out = [];
        foreach (self::ORDER as $key) {
            $lines = match ($key) {
                'medicines' => array_map(fn (array $m) => $m['product'].' : '.implode(', ', array_filter([$m['posology'], $m['duration']])), $copied['medicines'] ?? []),
                'follow_up' => isset($copied['follow_up']) ? [$copied['follow_up']['label'].($copied['follow_up']['with'] ? ', '.$copied['follow_up']['with'] : '')] : [],
                'call' => [...self::lines($sections['call']), ...array_filter([trim(implode(', ', array_filter([$copied['clinic']['name'] ?? null, $copied['clinic']['phone'] ?? null])))])],
                default => self::lines($sections[$key]),
            };
            if ($lines !== []) {
                $out[] = ['key' => $key, 'title' => DischargeLetter::SECTION_LABELS[$key] ?? self::COPIED_LABELS[$key], 'lines' => array_values($lines)];
            }
        }

        return $out;
    }

    /** Le texte envoyé au propriétaire */
    public function plainText(Sejour $sejour, DischargeLetter $letter): string
    {
        $head = 'Lettre de sortie de '.$sejour->patient?->nom.', relue et signée par '.$letter->signed_by_name.' :';
        $body = array_map(fn (array $b) => $b['title']."\n".implode("\n", $b['key'] === 'medicines' ? array_map(fn ($l) => '• '.$l, $b['lines']) : $b['lines']), $this->blocks($sejour, $letter));

        return $head."\n\n".implode("\n\n", $body);
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: []), fn (string $l) => $l !== ''));
    }
}
