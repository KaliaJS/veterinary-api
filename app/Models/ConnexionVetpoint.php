<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneClinique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La clé Vetpoint d'une clinique (27.10.2026) : chiffrée, jamais sérialisée,
 * lue dans la clinique du jeton seulement (scope `clinique`).
 */
class ConnexionVetpoint extends Model
{
    use AppartientAUneClinique;

    protected $table = 'connexions_vetpoint';

    protected $fillable = ['clinique_id', 'cle', 'verifiee_le'];

    protected $hidden = ['cle'];

    protected function casts(): array
    {
        return ['cle' => 'encrypted', 'verifiee_le' => 'datetime'];
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }
}
