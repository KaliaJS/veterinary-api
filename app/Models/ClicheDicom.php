<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cliché DICOM d'un examen d'imagerie — métadonnées du flux poussé
 * par la modalité (identifiants DICOM, chemin du fichier). Enfant sans
 * scope propre, résolu via l'examen. Idempotent par SOP Instance UID.
 */
class ClicheDicom extends Model
{
    protected $table = 'cliches_dicom';

    protected $fillable = [
        'examen_imagerie_id', 'sop_instance_uid', 'study_instance_uid',
        'series_instance_uid', 'nom_fichier', 'chemin', 'metadonnees',
    ];

    protected function casts(): array
    {
        return [
            'metadonnees' => 'array',
        ];
    }

    /** @return BelongsTo<ExamenImagerie, $this> */
    public function examen(): BelongsTo
    {
        return $this->belongsTo(ExamenImagerie::class, 'examen_imagerie_id');
    }
}
