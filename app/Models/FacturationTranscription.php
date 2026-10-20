<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Minutes de transcription d'une période d'abonnement facturées à une
 * organisation : une ligne par période, gage qu'une période n'est jamais
 * facturée deux fois, et la facture qui les porte (renouvellement,
 * changement de formule, ou fin d'abonnement).
 */
class FacturationTranscription extends Model
{
    protected $table = 'facturations_transcription';

    protected $fillable = [
        'organisation_id', 'periode_debut', 'periode_fin', 'secondes', 'minutes', 'montant_centimes', 'organisation_facture_id',
    ];

    protected function casts(): array
    {
        return [
            'periode_debut' => 'date',
            'periode_fin' => 'date',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
