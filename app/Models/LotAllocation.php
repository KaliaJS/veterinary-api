<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneClinique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Ce qu'une sortie de stock a pris dans un lot, ou ce qu'une annulation
 * y a rendu (lots décomptés à chaque sortie, FEFO, décision de Jim du
 * 04.11.2026). Écrit par FefoLots seul, sous le verrou de l'article.
 * Immuable : une erreur se corrige par un rendu, jamais en réécrivant.
 *
 * @property string $kind take | return | entry | reconciliation
 * @property int $quantity signée : − pris, + rendu
 * @property string|null $lot_number vide : stock sans lot connu
 * @property Carbon|null $expires_on
 * @property int|string|null $returned ce qui a déjà été rendu à cette prise (withSum des rendus)
 */
class LotAllocation extends Model
{
    use AppartientAUneClinique;

    public const string TAKE = 'take';

    public const string RETURN = 'return';

    /** Entrée dans un lot sans prise à rendre : la réception d'un stupéfiant dans le lot inscrit au registre */
    public const string ENTRY = 'entry';

    public const string RECONCILIATION = 'reconciliation';

    public const null UPDATED_AT = null;

    protected $fillable = [
        'kind', 'mouvement_stock_id', 'produit_id', 'clinique_id', 'lot_id', 'lot_number', 'expires_on',
        'quantity', 'returns_allocation_id', 'patient_id', 'client_id', 'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Le décompte des lots est immuable : rendez la quantité par un nouveau mouvement.'));
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'expires_on' => 'date',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MouvementStock, $this> */
    public function mouvement(): BelongsTo
    {
        return $this->belongsTo(MouvementStock::class, 'mouvement_stock_id');
    }

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Les rendus de cette prise
     *
     * @return HasMany<LotAllocation, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(self::class, 'returns_allocation_id');
    }
}
