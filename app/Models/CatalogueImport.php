<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Journal des imports du catalogue (Swissmedic, Refdata). */
class CatalogueImport extends Model
{
    public const SWISSMEDIC = 'swissmedic';

    public const REFDATA = 'refdata';

    public const REUSSI = 'reussi';

    public const IDENTIQUE = 'identique';

    public const ECHEC = 'echec';

    protected $fillable = ['source', 'fichier_hash', 'fichier_etag', 'export_le', 'statut', 'ajoutes', 'modifies', 'retires', 'message', 'duree_ms'];

    protected function casts(): array
    {
        return ['export_le' => 'date'];
    }
}
