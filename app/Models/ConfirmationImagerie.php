<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un diagnostic de l'examen confirmé ou infirmé après coup (28.10.2026) :
 * par la chirurgie, l'histologie, l'évolution… avec sa date et, s'il y en
 * a un, le document du dossier qui le prouve. Enfant sans scope propre,
 * résolu via son examen.
 */
class ConfirmationImagerie extends Model
{
    protected $table = 'confirmations_imagerie';

    protected $fillable = ['examen_imagerie_id', 'etiquette', 'resultat', 'source', 'constate_le', 'document_id', 'note', 'auteur_id'];

    protected function casts(): array
    {
        return [
            'constate_le' => 'date',
        ];
    }

    /** @return BelongsTo<ExamenImagerie, $this> */
    public function examen(): BelongsTo
    {
        return $this->belongsTo(ExamenImagerie::class, 'examen_imagerie_id');
    }

    /** @return BelongsTo<User, $this> */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
