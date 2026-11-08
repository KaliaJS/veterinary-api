<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Une donnée de base de l'OSAV (espèce, clé de diagnostic, fréquence, voie),
 * commune à toutes les cliniques (08.11.2026).
 */
class IsabvCode extends Model
{
    public const KIND_SPECIES = 'species';

    public const KIND_DIAGNOSIS = 'diagnosis';

    public const KIND_FREQUENCY = 'frequency';

    public const KIND_ROUTE = 'route';

    protected $fillable = ['kind', 'species_code', 'code', 'parent_code', 'label_fr', 'label_de', 'label_it', 'category', 'per_day', 'is_valid_for_consultations'];

    protected function casts(): array
    {
        return ['per_day' => 'float', 'is_valid_for_consultations' => 'boolean'];
    }

    /** @param Builder<IsabvCode> $query */
    public function scopeOfKind(Builder $query, string $kind): void
    {
        $query->where('kind', $kind);
    }

    /** Le niveau d'une clé de diagnostic : « 3.7.1 » est au niveau 3 */
    public static function level(string $code): int
    {
        return count(explode('.', $code));
    }
}
