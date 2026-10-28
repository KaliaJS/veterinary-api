<?php

namespace App\Models;

use App\Events\FacturePlusMisAJour;
use App\Support\TempsReel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Facture de l'offre Plus d'un propriétaire : reflet d'une facture Stripe
 * finalisée (numéro, statut, montants, PDF). Jamais modifiée à la main.
 */
class FactureProprietaire extends Model
{
    protected $table = 'proprietaire_factures';

    protected static function booted(): void
    {
        // Temps réel (28.10.2026) : l'espace du propriétaire suit le paiement sans relire
        static::saved(function (FactureProprietaire $facture): void {
            if ($facture->wasRecentlyCreated || $facture->wasChanged(['statut', 'paye_le', 'metadonnees'])) {
                TempsReel::diffuser(fn () => FacturePlusMisAJour::dispatch('utilisateur.'.$facture->user_id, ['id' => $facture->id]));
            }
        });
    }

    protected $fillable = ['user_id', 'numero', 'emise_le', 'libelle', 'montant'];

    protected function casts(): array
    {
        return [
            'emise_le' => 'date',
            'montant' => 'decimal:2',
            'montant_ht' => 'decimal:2',
            'tva' => 'decimal:2',
            'metadonnees' => 'array',
            'lignes' => 'array',
            'periode_debut' => 'date',
            'periode_fin' => 'date',
            'paye_le' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
