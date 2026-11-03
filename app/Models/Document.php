<?php

namespace App\Models;

use App\Models\Concerns\RecordsSourceOrganisation;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, RecordsSourceOrganisation;

    protected $fillable = ['patient_id', 'nom', 'type', 'chemin', 'nom_fichier', 'taille_octets', 'visible_proprietaire', 'signataire', 'ajoute_par', 'etabli_le', 'source_organisation_id', 'client_id'];

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
}
