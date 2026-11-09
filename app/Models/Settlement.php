<?php

namespace App\Models;

use App\Enums\SettlementMethod;
use App\Models\Concerns\AppartientAUneOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Un règlement de facture (09.11.2026) : un paiement reçu, daté du jour où
 * l'argent est arrivé ; négatif, une annulation (`reverses_id`) ou un
 * remboursement. Jamais modifié ni effacé : une erreur s'annule par une
 * ligne de plus (règle de Jim). Niveau organisation, comme la facture ; la
 * clinique est celle de la facture, où passe sa caisse.
 */
class Settlement extends Model
{
    use AppartientAUneOrganisation;

    protected $fillable = [
        'organisation_id', 'clinique_id', 'facture_id', 'method', 'amount', 'received_on',
        'reference', 'paiement_id', 'reverses_id', 'reason', 'cloture_caisse_id', 'recorded_by_id',
    ];

    protected function casts(): array
    {
        return [
            'method' => SettlementMethod::class,
            'amount' => 'decimal:2',
            'received_on' => 'date:Y-m-d',
        ];
    }

    /** @return BelongsTo<Facture, $this> */
    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Paiement, $this> */
    public function paiement(): BelongsTo
    {
        return $this->belongsTo(Paiement::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    /** @return BelongsTo<Settlement, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(Settlement::class, 'reverses_id')->withoutGlobalScopes();
    }

    /** @return HasOne<Settlement, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(Settlement::class, 'reverses_id')->withoutGlobalScopes();
    }

    /**
     * Annulable à la main : un paiement reçu, pas déjà annulé, ni passé par
     * Stripe (il se rembourse chez Stripe), ni l'acompte en ligne.
     */
    public function isReversible(): bool
    {
        return (float) $this->amount > 0
            && $this->reverses_id === null
            && $this->paiement_id === null
            && $this->method !== SettlementMethod::Acompte
            && ! ($this->relationLoaded('reversal') ? $this->reversal !== null : $this->reversal()->exists());
    }
}
