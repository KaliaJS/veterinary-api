<?php

namespace App\Models;

use App\Enums\StatutCommandeBoutique;
use App\Models\Concerns\AppartientAUneClinique;
use Database\Factories\CommandeBoutiqueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Commande de la boutique en ligne, rattachée à la clinique de RETRAIT.
 * Cycle : à préparer → prête → retirée ; la remise sort le stock. Le
 * total est dérivé des lignes (prix figés à la commande).
 */
class CommandeBoutique extends Model
{
    /** @use HasFactory<CommandeBoutiqueFactory> */
    use AppartientAUneClinique, HasFactory;

    protected $table = 'commandes_boutique';

    protected $fillable = [
        'clinique_id', 'numero', 'client_id', 'client_nom', 'statut', 'passee_le', 'retiree_le',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutCommandeBoutique::class,
            'passee_le' => 'datetime',
            'retiree_le' => 'datetime',
        ];
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return HasMany<CommandeBoutiqueLigne, $this> */
    public function lignes(): HasMany
    {
        return $this->hasMany(CommandeBoutiqueLigne::class, 'commande_boutique_id');
    }

    public function totalTtc(): float
    {
        $lignes = $this->relationLoaded('lignes') ? $this->lignes : $this->lignes()->get();

        return round((float) $lignes->sum(fn (CommandeBoutiqueLigne $ligne): float => $ligne->quantite * (float) $ligne->prix_chf), 2);
    }
}
