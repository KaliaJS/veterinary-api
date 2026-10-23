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

    /** Miroir des défauts de la base, connus dès la création (sans relire la ligne) */
    protected $attributes = [
        'assujettie_tva' => true,
        'paiements_actifs' => false,
        'virements_actifs' => false,
        'paiements_a_completer' => false,
    ];

    protected $fillable = [
        'organisation_id', 'nom', 'ide', 'assujettie_tva',
        'stripe_compte_id', 'paiements_actifs', 'virements_actifs', 'paiements_a_completer', 'paiements_maj_le',
    ];

    protected function casts(): array
    {
        return [
            'assujettie_tva' => 'boolean',
            'paiements_actifs' => 'boolean',
            'virements_actifs' => 'boolean',
            'paiements_a_completer' => 'boolean',
            'paiements_maj_le' => 'datetime',
        ];
    }

    /** Taux de TVA de ses factures, en % : le taux normal, 0 hors assujettissement. */
    public function tauxTva(): float
    {
        return $this->assujettie_tva === false ? 0.0 : Facture::TAUX_TVA * 100;
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
