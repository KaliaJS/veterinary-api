<?php

namespace App\Models;

use App\Models\Concerns\RecordsSourceOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un examen neurologique (08.11.2026) : les signes relevés, sans aucune
 * localisation (choix de Jim). Les valeurs permises sont ici ; `null`
 * veut dire « non examiné ». Les formes JSON sont toujours complètes à la
 * lecture (toutes les pattes, tous les tests), pour que l'app n'ait rien à
 * supposer.
 */
class NeuroExam extends Model
{
    use RecordsSourceOrganisation;

    protected $table = 'neuro_exams';

    protected $fillable = [
        'patient_id', 'day', 'mental_status', 'gait_score', 'limbs', 'spine',
        'cranial_nerves', 'note', 'by_id', 'by_name', 'source_organisation_id',
    ];

    public const MENTAL_STATUSES = ['alert', 'obtunded', 'stuporous', 'comatose'];

    /** Score de Frankel modifié : 0 (paraplégie sans douleur profonde) à 5 (douleur spinale seule) */
    public const GAIT_SCORE_MAX = 5;

    public const LIMBS = ['left_front', 'right_front', 'left_hind', 'right_hind'];

    public const HIND_LIMBS = ['left_hind', 'right_hind'];

    public const MOTOR = ['normal', 'ambulatory_paresis', 'non_ambulatory_paresis', 'plegia'];

    public const PROPRIOCEPTION = ['normal', 'decreased', 'absent'];

    public const REFLEX = ['absent', 'decreased', 'normal', 'increased'];

    public const DEEP_PAIN = ['present', 'absent'];

    public const SPINE_REGIONS = ['cervical', 'thoracic', 'lumbar', 'lumbosacral'];

    public const CUTANEOUS_TRUNCI = ['normal', 'cutoff'];

    public const CRANIAL_TESTS = ['menace', 'pupillary_light', 'palpebral', 'corneal', 'facial_sensation', 'physiological_nystagmus'];

    public const CRANIAL_VALUES = ['normal', 'decreased', 'absent'];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'gait_score' => 'integer',
            'limbs' => 'array',
            'spine' => 'array',
            'cranial_nerves' => 'array',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Les vertèbres où le réflexe cutané du tronc peut s'arrêter : T1 à T13, L1 à L7
     *
     * @return list<string>
     */
    public static function vertebrae(): array
    {
        return [...array_map(fn (int $i) => 'T'.$i, range(1, 13)), ...array_map(fn (int $i) => 'L'.$i, range(1, 7))];
    }

    /**
     * Les quatre pattes, chaque signe présent (null : non examiné) ; le
     * patellaire seulement aux postérieurs.
     *
     * @param  array<string, mixed>|null  $limbs
     * @return array<string, array<string, string|null>>
     */
    public static function normalizeLimbs(?array $limbs): array
    {
        $out = [];
        foreach (self::LIMBS as $k) {
            $l = is_array($limbs[$k] ?? null) ? $limbs[$k] : [];
            $out[$k] = [
                'motor' => $l['motor'] ?? null,
                'proprioception' => $l['proprioception'] ?? null,
                'withdrawal' => $l['withdrawal'] ?? null,
                'patellar' => in_array($k, self::HIND_LIMBS, true) ? ($l['patellar'] ?? null) : null,
                'deep_pain' => $l['deep_pain'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Le dos : les régions douloureuses dans l'ordre du corps, le réflexe
     * cutané du tronc et sa vertèbre de coupure (seulement s'il est coupé).
     *
     * @param  array<string, mixed>|null  $spine
     * @return array{painful_regions: list<string>, cutaneous_trunci: string|null, cutoff_at: string|null}
     */
    public static function normalizeSpine(?array $spine): array
    {
        $regions = is_array($spine['painful_regions'] ?? null) ? $spine['painful_regions'] : [];
        $cutaneous = $spine['cutaneous_trunci'] ?? null;

        return [
            'painful_regions' => array_values(array_filter(self::SPINE_REGIONS, fn (string $r) => in_array($r, $regions, true))),
            'cutaneous_trunci' => $cutaneous,
            'cutoff_at' => $cutaneous === 'cutoff' ? ($spine['cutoff_at'] ?? null) : null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $nerves
     * @return array<string, array{left: string|null, right: string|null}>
     */
    public static function normalizeCranialNerves(?array $nerves): array
    {
        $out = [];
        foreach (self::CRANIAL_TESTS as $k) {
            $t = is_array($nerves[$k] ?? null) ? $nerves[$k] : [];
            $out[$k] = ['left' => $t['left'] ?? null, 'right' => $t['right'] ?? null];
        }

        return $out;
    }

    /** Le jour même et dans l'organisation qui l'a fait : l'examen se modifie encore */
    public function isEditableBy(?int $organisationId): bool
    {
        return $this->day->isSameDay(aujourdhui()) && $organisationId !== null && (int) $this->source_organisation_id === $organisationId;
    }
}
