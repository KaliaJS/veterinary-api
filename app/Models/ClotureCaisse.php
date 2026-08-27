<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneClinique;
use Database\Factories\ClotureCaisseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Clôture de caisse (Z) — par CLINIQUE : la caisse est un tiroir
 * physique. Les totaux par moyen sont figés à l'arrêté ; les factures
 * couvertes pointent la clôture et n'entrent plus dans la suivante.
 */
class ClotureCaisse extends Model
{
    /** @use HasFactory<ClotureCaisseFactory> */
    use AppartientAUneClinique, HasFactory;

    protected $table = 'clotures_caisse';

    protected $fillable = [
        'clinique_id', 'numero', 'arretee_le', 'user_id', 'total_tpe',
        'total_twint', 'total_especes', 'fond', 'especes_comptees', 'ecart',
        'depot', 'nouveau_fond', 'note',
    ];

    protected function casts(): array
    {
        return [
            'arretee_le' => 'datetime',
            'total_tpe' => 'decimal:2',
            'total_twint' => 'decimal:2',
            'total_especes' => 'decimal:2',
            'fond' => 'decimal:2',
            'especes_comptees' => 'decimal:2',
            'ecart' => 'decimal:2',
            'depot' => 'decimal:2',
            'nouveau_fond' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Factures couvertes par cette clôture.
     * @return HasMany<Facture, $this> */
    public function factures(): HasMany
    {
        return $this->hasMany(Facture::class);
    }
}
