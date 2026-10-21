<?php

namespace App\Models;

use App\Enums\TypeParcours;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une ligne du parcours d'un vétérinaire (poste ou formation), qu'il déclare lui-même. */
class ParcoursVeterinaire extends Model
{
    protected $table = 'parcours_veterinaire';

    protected $fillable = ['type', 'titre', 'lieu', 'ville', 'annee_debut', 'annee_fin', 'ordre'];

    protected function casts(): array
    {
        return [
            'type' => TypeParcours::class,
            'annee_debut' => 'integer',
            'annee_fin' => 'integer',
            'ordre' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, mixed> */
    public function versTableau(): array
    {
        return [
            'type' => $this->type->value,
            'titre' => $this->titre,
            'lieu' => $this->lieu,
            'ville' => $this->ville,
            'annee_debut' => $this->annee_debut,
            'annee_fin' => $this->annee_fin,
        ];
    }
}
