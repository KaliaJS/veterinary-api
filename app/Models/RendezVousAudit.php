<?php

namespace App\Models;

use Database\Factories\RendezVousAuditFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trace immuable : une ligne par champ modifié, `batch_id` groupe les
 * champs d'un même geste (l'historique s'affiche par geste et l'undo
 * annule un lot entier).
 */
class RendezVousAudit extends Model
{
    /** @use HasFactory<RendezVousAuditFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'rendez_vous_audits';

    protected $fillable = [
        'rendez_vous_id', 'user_id', 'batch_id', 'champ',
        'ancienne_valeur', 'nouvelle_valeur', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'ancienne_valeur' => 'json',
            'nouvelle_valeur' => 'json',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<RendezVous, $this> */
    public function rendezVous(): BelongsTo
    {
        return $this->belongsTo(RendezVous::class, 'rendez_vous_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
