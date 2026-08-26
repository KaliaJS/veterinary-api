<?php

namespace App\Models;

use Database\Factories\CompteRenduFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Compte rendu SOAP d'un rendez-vous (un seul par RDV — upsert).
 * Pas de scope propre : jamais résolu directement, toujours atteint
 * via son rendez-vous — résolu au NIVEAU ORGANISATION comme tout le
 * dossier médical (patient partagé entre les cliniques du jeton).
 */
class CompteRendu extends Model
{
    /** @use HasFactory<CompteRenduFactory> */
    use HasFactory;

    protected $table = 'comptes_rendus';

    protected $fillable = ['rendez_vous_id', 's', 'o', 'a', 'p', 'user_id'];

    /** @return BelongsTo<RendezVous, $this> */
    public function rendezVous(): BelongsTo
    {
        return $this->belongsTo(RendezVous::class, 'rendez_vous_id');
    }

    /** Signataire — l'utilisateur authentifié au dernier enregistrement.
     * @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
