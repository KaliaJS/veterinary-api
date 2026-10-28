<?php

namespace App\Models;

use App\Enums\MoyenPaiement;
use App\Enums\StatutPaiement;
use App\Events\PaiementMisAJour;
use App\Models\Concerns\AppartientAUneClinique;
use App\Support\TempsReel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tentative de paiement d'une facture par Stripe (une intention de
 * paiement par tentative). Réussie, elle encaisse la facture : le
 * total TPE / TWINT de la caisse est alors celui de Stripe, sans
 * rapprochement manuel.
 */
class Paiement extends Model
{
    use AppartientAUneClinique;

    /**
     * Chaque changement d'état est diffusé à la clinique (Reverb,
     * 28.10.2026), une fois la transaction validée, sans faire échouer le
     * paiement si la diffusion échoue : l'écran ne relit plus.
     */
    protected static function booted(): void
    {
        static::created(fn (Paiement $paiement) => TempsReel::diffuser(fn () => PaiementMisAJour::dispatch($paiement->fresh() ?? $paiement)));
        static::updated(function (Paiement $paiement): void {
            if ($paiement->wasChanged(['statut', 'erreur', 'url_twint', 'terminal_paiement_id'])) {
                TempsReel::diffuser(fn () => PaiementMisAJour::dispatch($paiement->fresh() ?? $paiement));
            }
            // Litige, remboursement : les alertes de paiement de la clinique changent
            if ($paiement->wasChanged('statut')) {
                TempsReel::alertes((int) $paiement->clinique_id);
            }
        });
    }

    protected $fillable = [
        'facture_id', 'clinique_id', 'moyen', 'montant', 'stripe_payment_intent_id', 'stripe_compte_id',
        'terminal_paiement_id', 'statut', 'erreur', 'url_twint', 'user_id', 'reussi_le',
    ];

    protected function casts(): array
    {
        return [
            'moyen' => MoyenPaiement::class,
            'statut' => StatutPaiement::class,
            'montant' => 'decimal:2',
            'reussi_le' => 'datetime',
        ];
    }

    /** @return BelongsTo<Facture, $this> */
    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }

    /** @return BelongsTo<TerminalPaiement, $this> */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(TerminalPaiement::class, 'terminal_paiement_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
