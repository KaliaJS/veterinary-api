<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Une ouverture ou un téléchargement complet d'un lien de partage du dossier (09.11.2026) */
class RecordShareEvent extends Model
{
    protected $table = 'record_share_events';

    public $timestamps = false;

    protected $fillable = ['record_share_id', 'kind', 'at'];

    protected function casts(): array
    {
        return ['at' => 'datetime'];
    }
}
