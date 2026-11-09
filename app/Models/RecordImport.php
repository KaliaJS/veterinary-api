<?php

namespace App\Models;

use App\Events\RecordImportUpdated;
use App\Models\Concerns\AppartientAUneClinique;
use App\Support\TempsReel;
use Illuminate\Database\Eloquent\Model;

/**
 * Un gros PDF reçu à découper (étape 3, 09.11.2026) : l'état de la
 * découpe et la proposition de Claude Haiku 4.5. Par clinique.
 */
class RecordImport extends Model
{
    use AppartientAUneClinique;

    protected $table = 'record_imports';

    protected $fillable = ['organisation_id', 'clinique_id', 'patient_id', 'file_name', 'pages_count', 'status', 'error', 'proposal', 'documents_count', 'created_by_id'];

    protected $attributes = ['status' => 'splitting'];

    /** Les sortes de pièces que la découpe connaît (celles de l'onglet Documents) */
    public const KINDS = ['compte_rendu', 'rapport', 'laboratoire', 'imagerie', 'specialiste', 'ordonnance', 'vaccination', 'lettre', 'autre'];

    protected function casts(): array
    {
        return [
            'proposal' => 'encrypted:array',
            'pages_count' => 'integer',
            'documents_count' => 'integer',
        ];
    }

    /** Le poste qui attend relit la découpe (Reverb), après la transaction */
    public static function announce(int $id, int $cliniqueId): void
    {
        TempsReel::diffuser(function () use ($id, $cliniqueId): void {
            broadcast(new RecordImportUpdated($id, $cliniqueId));
        });
    }

    /** Le fichier provisoire des pages (texte, images des pages scannées), effacé après la découpe */
    public function pagesPath(): string
    {
        return 'imports/'.$this->organisation_id.'/'.$this->id.'.json';
    }
}
