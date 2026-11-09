<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un mois remis à la fiduciaire (09.11.2026) : ses écritures ne bougent
 * plus. Un virement ne s'y date plus ; un acompte ou un remboursement qui
 * y tomberait se date du jour ; les lignes de ses factures sont figées.
 */
class AccountingLock extends Model
{
    protected $fillable = ['societe_id', 'month', 'locked_at', 'locked_by_id', 'entries', 'reopened_at', 'reopened_by_id'];

    protected function casts(): array
    {
        return ['locked_at' => 'datetime', 'reopened_at' => 'datetime', 'entries' => 'array'];
    }

    /** @param  Builder<self>  $query */
    public function scopeActif($query): void
    {
        $query->whereNull('reopened_at');
    }

    /** @return BelongsTo<Societe, $this> */
    public function societe(): BelongsTo
    {
        return $this->belongsTo(Societe::class);
    }

    /** @return BelongsTo<User, $this> */
    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by_id');
    }

    /** Le mois de ce jour est-il remis à la fiduciaire pour la société de cette clinique ? */
    public static function isLocked(int $cliniqueId, string $day): bool
    {
        $societeId = Clinique::query()->withoutGlobalScopes()->whereKey($cliniqueId)->value('societe_id');

        return $societeId !== null && self::query()->actif()->where('societe_id', $societeId)->where('month', substr($day, 0, 7))->exists();
    }
}
