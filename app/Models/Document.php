<?php

namespace App\Models;

use App\Models\Concerns\RecordsAuthor;
use App\Models\Concerns\RecordsSourceOrganisation;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, RecordsAuthor, RecordsSourceOrganisation;

    protected $fillable = ['patient_id', 'nom', 'type', 'chemin', 'nom_fichier', 'taille_octets', 'visible_proprietaire', 'signataire', 'ajoute_par', 'employe_id', 'etabli_le', 'source_organisation_id', 'client_id'];

    protected function casts(): array
    {
        return [
            'etabli_le' => 'date',
            'taille_octets' => 'integer',
            'visible_proprietaire' => 'boolean',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** Qui l'a ajouté au dossier (06.11.2026)
     * @return BelongsTo<Employe, $this> */
    public function employe(): BelongsTo
    {
        return $this->belongsTo(Employe::class)->withoutGlobalScope('clinique');
    }
}
