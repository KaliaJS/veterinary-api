<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ce que le vétérinaire dessine sur une radio (28.10.2026) : gardé à part,
 * jamais dans le DICOM. Points en pixels de l'image d'origine (colonne,
 * ligne), étiquette d'une liste fixe (config/imagerie.php), certitude,
 * auteur et date. Enfant sans scope propre, résolu via son cliché.
 */
class AnnotationImagerie extends Model
{
    protected $table = 'annotations_imagerie';

    protected $fillable = ['cliche_dicom_id', 'type', 'points', 'image', 'etiquette', 'certitude', 'note', 'auteur_id', 'version_schema'];

    protected function casts(): array
    {
        return [
            'points' => 'array',
            'image' => 'integer',
            'version_schema' => 'integer',
        ];
    }

    /** @return BelongsTo<ClicheDicom, $this> */
    public function cliche(): BelongsTo
    {
        return $this->belongsTo(ClicheDicom::class, 'cliche_dicom_id');
    }

    /** @return BelongsTo<User, $this> */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
