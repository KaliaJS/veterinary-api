<?php

namespace App\Models;

use Database\Factories\SocieteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Societe extends Model
{
    /** @use HasFactory<SocieteFactory> */
    use HasFactory;

    protected $fillable = [
        'organisation_id', 'nom', 'ide',
        'stripe_compte_id', 'paiements_actifs', 'virements_actifs', 'paiements_a_completer', 'paiements_maj_le',
    ];

    protected function casts(): array
    {
        return [
            'paiements_actifs' => 'boolean',
            'virements_actifs' => 'boolean',
            'paiements_a_completer' => 'boolean',
            'paiements_maj_le' => 'datetime',
        ];
    }

    /** Le compte de paiement existe et peut encaisser (terminal, TWINT, acomptes). */
    public function encaisseEnLigne(): bool
    {
        return $this->stripe_compte_id !== null && $this->paiements_actifs;
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return HasMany<Clinique, $this> */
    public function cliniques(): HasMany
    {
        return $this->hasMany(Clinique::class);
    }
}
