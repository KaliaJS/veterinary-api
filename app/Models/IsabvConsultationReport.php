<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneClinique;
use Illuminate\Database\Eloquent\Model;

/** Le nombre de consultations d'une espèce et d'une année, envoyé à l'OSAV (08.11.2026). */
class IsabvConsultationReport extends Model
{
    use AppartientAUneClinique;

    protected $fillable = ['clinique_id', 'year', 'species_code', 'count', 'status', 'osav_message', 'sent_at'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'count' => 'integer', 'sent_at' => 'datetime'];
    }
}
