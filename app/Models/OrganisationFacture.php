<?php

namespace App\Models;

use Database\Factories\OrganisationFactureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Facture d'abonnement ou de pack de quota (« F-2026-081 ») — émise par
 * l'éditeur à l'organisation, jamais modifiée ni supprimée. Sans scope
 * propre : elle se résout toujours via l'organisation du jeton.
 */
class OrganisationFacture extends Model
{
    /** @use HasFactory<OrganisationFactureFactory> */
    use HasFactory;

    /** Miroir des défauts de la base, connus dès la création (sans relire la ligne) */
    protected $attributes = [
        'quota_applique' => false,
    ];

    protected $fillable = ['organisation_id', 'numero', 'emise_le', 'libelle', 'montant'];

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
            'quota_applique' => 'boolean',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
