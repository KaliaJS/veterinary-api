<?php

namespace App\Models;

use App\Enums\EtatDent;
use App\Models\Concerns\RecordsSourceOrganisation;
use Database\Factories\ReleveDentaireFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReleveDentaire extends Model
{
    /** @use HasFactory<ReleveDentaireFactory> */
    use HasFactory, RecordsSourceOrganisation;

    protected $table = 'releves_dentaires';

    protected $fillable = ['patient_id', 'dent', 'etat', 'source_organisation_id'];

    protected function casts(): array
    {
        return [
            'dent' => 'integer',
            'etat' => EtatDent::class,
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
