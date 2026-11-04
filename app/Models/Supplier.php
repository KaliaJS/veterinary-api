<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneClinique;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un fournisseur ou une centrale d'achat de la clinique (décisions de Jim,
 * 03.11.2026) : son canal (`api` par le connecteur d'une centrale, `email`
 * avec le bon en PDF, `manual`), son minimum de commande, ses jours et son
 * heure de commande, la règle sous le minimum ; l'avis part toujours, au moins 12 heures avant chaque départ.
 * Rien de Vetpoint ici : la centrale est une clé de config/purchasing.php.
 */
class Supplier extends Model
{
    use AppartientAUneClinique;

    public const array CHANNELS = ['api', 'email', 'manual'];

    public const array UNDER_MINIMUM = ['ask', 'send', 'wait'];

    /** Miroir des défauts de la base, connus dès la création */
    protected $attributes = [
        'channel' => 'manual',
        'minimum_order' => 0,
        'under_minimum' => 'ask',
    ];

    protected $fillable = [
        'clinique_id', 'name', 'channel', 'connector', 'email', 'minimum_order',
        'order_days', 'order_time', 'under_minimum',
    ];

    protected function casts(): array
    {
        return [
            'minimum_order' => 'decimal:2',
            'order_days' => 'array',
        ];
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }

    /** @return HasMany<ProductSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(ProductSource::class);
    }

    /**
     * La prochaine date butoir après un instant : le premier jour de commande
     * à l'heure dite, à l'heure de la clinique ; sans jour de commande, aucune.
     */
    public function nextDeadline(?CarbonImmutable $after = null, ?string $timezone = null): ?CarbonImmutable
    {
        $days = array_values(array_filter(array_map('intval', $this->order_days ?? []), fn (int $day): bool => $day >= 1 && $day <= 7));
        if ($days === []) {
            return null;
        }
        $timezone ??= $this->clinique()->withoutGlobalScopes()->value('fuseau_horaire') ?: 'Europe/Zurich';
        $after = ($after ?? CarbonImmutable::now())->setTimezone($timezone);
        [$hour, $minute] = array_map('intval', explode(':', $this->order_time ?: '12:00') + [1 => '0']);

        for ($offset = 0; $offset <= 7; $offset++) {
            $candidate = $after->startOfDay()->addDays($offset)->setTime($hour, $minute);
            if (in_array($candidate->dayOfWeekIso, $days, true) && $candidate->greaterThan($after)) {
                return $candidate->utc();
            }
        }

        return null;
    }
}
