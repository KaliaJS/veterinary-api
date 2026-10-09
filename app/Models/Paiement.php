<?php

namespace App\Models;

use App\Enums\MoyenPaiement;
use App\Enums\StatutPaiement;
use App\Models\Concerns\AppartientAUneClinique;
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

    protected $fillable = [
        'facture_id', 'clinique_id', 'moyen', 'montant', 'stripe_payment_intent_id',
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
