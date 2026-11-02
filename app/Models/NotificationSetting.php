<?php

namespace App\Models;

use App\Enums\PushType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Les réglages des notifications du téléphone d'un compte (02.11.2026).
 * Sans ligne enregistrée : tout permis, nuit de 22 h à 7 h, urgences même
 * la nuit (réglable par chacun).
 */
class NotificationSetting extends Model
{
    protected $fillable = ['user_id', 'disabled_types', 'quiet_enabled', 'quiet_from', 'quiet_to', 'urgent_at_night', 'timezone'];

    protected $attributes = [
        'disabled_types' => '[]',
        'quiet_enabled' => true,
        'quiet_from' => '22:00',
        'quiet_to' => '07:00',
        'urgent_at_night' => true,
        'timezone' => 'Europe/Zurich',
    ];

    protected function casts(): array
    {
        return [
            'disabled_types' => 'array',
            'quiet_enabled' => 'boolean',
            'urgent_at_night' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function for(int $userId): self
    {
        return self::query()->firstOrNew(['user_id' => $userId]);
    }

    /** Cette sorte, maintenant : à envoyer ? (coupée, ou la nuit sauf urgence permise) */
    public function allows(PushType $type, ?CarbonImmutable $now = null): bool
    {
        if (in_array($type->value, $this->disabled_types ?? [], true)) {
            return false;
        }
        if (! $this->quiet_enabled || ($type === PushType::Emergency && $this->urgent_at_night)) {
            return true;
        }

        return ! $this->isQuietAt(($now ?? CarbonImmutable::now())->setTimezone($this->timezone ?: 'Europe/Zurich'));
    }

    /** La nuit, de `quiet_from` à `quiet_to` (qui peut passer minuit) */
    public function isQuietAt(CarbonImmutable $local): bool
    {
        $minutes = fn (string $hhmm): int => ((int) substr($hhmm, 0, 2)) * 60 + (int) substr($hhmm, 3, 2);
        $now = $local->hour * 60 + $local->minute;
        [$from, $to] = [$minutes((string) $this->quiet_from), $minutes((string) $this->quiet_to)];
        if ($from === $to) {
            return false;
        }

        return $from < $to ? ($now >= $from && $now < $to) : ($now >= $from || $now < $to);
    }
}
