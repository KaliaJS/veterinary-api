<?php

namespace App\Models;

use App\Enums\StatutCommandeFournisseur;
use App\Models\Concerns\AppartientAUneClinique;
use Database\Factories\CommandeFournisseurFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bon de commande fournisseur, rattaché à la clinique de livraison.
 * Cycle brouillon → envoyée → réceptionnée ; la suppression est tolérée
 * tant que la commande n'est pas réceptionnée (le stock a alors bougé).
 */
class CommandeFournisseur extends Model
{
    /** @use HasFactory<CommandeFournisseurFactory> */
    use AppartientAUneClinique, HasFactory;

    protected $table = 'commandes_fournisseur';

    protected $fillable = [
        'clinique_id', 'numero', 'fournisseur', 'statut', 'envoyee_le', 'receptionnee_le',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutCommandeFournisseur::class,
            'envoyee_le' => 'datetime',
            'receptionnee_le' => 'datetime',
        ];
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }

    /** @return HasMany<CommandeFournisseurLigne, $this> */
    public function lignes(): HasMany
    {
        return $this->hasMany(CommandeFournisseurLigne::class, 'commande_fournisseur_id');
    }
}
