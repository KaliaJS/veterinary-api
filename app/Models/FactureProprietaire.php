<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Facture de l'offre Plus d'un propriétaire : reflet d'une facture Stripe
 * finalisée (numéro, statut, montants, PDF). Jamais modifiée à la main.
 */
class FactureProprietaire extends Model
{
    protected $table = 'proprietaire_factures';

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
