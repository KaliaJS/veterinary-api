<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Minutes de transcription d'un mois facturées à une organisation : une
 * ligne par mois, gage qu'un mois n'est jamais facturé deux fois. La ligne
 * Stripe s'ajoute à la prochaine facture de l'abonnement, ou à une facture
 * à part sans abonnement en cours.
 */
class FacturationTranscription extends Model
{
    protected $table = 'facturations_transcription';

    protected $fillable = [
        'organisation_id', 'mois', 'secondes', 'minutes', 'montant_centimes', 'stripe_ligne_id', 'stripe_facture_id',
    ];

    protected function casts(): array
    {
        return [
            'mois' => 'date',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
