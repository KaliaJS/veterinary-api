<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneClinique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Les préférences d'une personne de l'équipe dans une clinique (04.11.2026) :
 * outils favoris et choix de masquer les autres ; la vue du schéma dentaire
 * (A, B, C ou D) ; le graphique des statistiques de la Caisse, en barres ou
 * en courbe (06.11.2026). Le scope de la clinique borne à la clinique du jeton ;
 * la personne se choisit par son compte.
 */
class StaffPreference extends Model
{
    use AppartientAUneClinique;

    protected $fillable = ['user_id', 'tool_favorites', 'hide_non_favorite_tools', 'dental_chart_view', 'stats_chart_style', 'waiting_room'];

    protected $attributes = [
        'tool_favorites' => '[]',
        'hide_non_favorite_tools' => false,
        'waiting_room' => false,
    ];

    protected function casts(): array
    {
        return [
            'tool_favorites' => 'array',
            'hide_non_favorite_tools' => 'boolean',
            'waiting_room' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }

    /** Celles du compte dans la clinique du jeton (nouvelles, sans ligne encore) */
    public static function for(int $userId): self
    {
        return self::query()->firstOrNew(['user_id' => $userId]);
    }
}
