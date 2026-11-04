<?php

namespace App\Models;

use App\Auth\ContexteClinique;
use App\Enums\StatutCommandeFournisseur;
use App\Models\Concerns\AppartientAUneClinique;
use Database\Factories\CommandeFournisseurFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bon de commande fournisseur de la clinique qui le reçoit, lu et piloté
 * depuis elle seule (décision de Jim, 28.10.2026). Cycle brouillon →
 * envoyée → réceptionnée ; la suppression est tolérée tant que la commande
 * n'est pas réceptionnée (le stock a alors bougé).
 */
class CommandeFournisseur extends Model
{
    /** Lecture chez Vetpoint au moment de la réception (pas une colonne) : rendue avec la commande, une fois */
    public ?array $receptionVetpoint = null;

    /** @use HasFactory<CommandeFournisseurFactory> */
    use AppartientAUneClinique, HasFactory;

    protected $table = 'commandes_fournisseur';

    protected $fillable = [
        'clinique_id', 'numero', 'fournisseur', 'statut', 'envoyee_le', 'receptionnee_le',
        // Le panier automatique (03.11.2026)
        'supplier_id', 'is_auto', 'deadline_at', 'paused_at', 'eve_notice_for', 'decision_asked_for',
        'moved_out', 'auto_error', 'sent_to', 'supplier_removed_at',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutCommandeFournisseur::class,
            'envoyee_le' => 'datetime',
            'vetpoint_envoi_le' => 'datetime',
            'vetpoint_date_souhaitee' => 'date',
            'receptionnee_le' => 'datetime',
            'is_auto' => 'boolean',
            'deadline_at' => 'datetime',
            'paused_at' => 'datetime',
            'eve_notice_for' => 'datetime',
            'decision_asked_for' => 'datetime',
            'moved_out' => 'array',
            'supplier_removed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }

    /**
     * Toutes cliniques de l'organisation du jeton, jamais au-delà. Sert
     * seulement aux contrôles transversaux (note Vetpoint d'une autre de nos
     * commandes) : on ne lit ni ne pilote d'ici la commande d'une clinique
     * sœur. Les articles couverts par une commande ouverte, eux, se lisent
     * dans la clinique ouverte (stock bas par clinique, 28.10.2026).
     *
     * @param  Builder<static>  $requete
     * @return Builder<static>
     */
    public function scopeDansLOrganisation(Builder $requete): Builder
    {
        return $requete
            ->withoutGlobalScope('clinique')
            ->whereIn('clinique_id', Clinique::query()
                ->select('cliniques.id')
                ->whereRelation('societe', 'organisation_id', app(ContexteClinique::class)->organisationId()));
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** Sous le minimum à sa date butoir, la clinique doit dire : envoyer, ou attendre */
    public function isAwaitingDecision(): bool
    {
        return $this->is_auto && $this->decision_asked_for !== null && $this->statut === StatutCommandeFournisseur::Brouillon;
    }

    /** @return HasMany<CommandeFournisseurLigne, $this> */
    public function lignes(): HasMany
    {
        return $this->hasMany(CommandeFournisseurLigne::class, 'commande_fournisseur_id');
    }
}
