<?php

namespace App\Models;

use App\Enums\ModaliteUrgences;
use App\Enums\ServiceClinique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un service proposé par une clinique (coché par l'administrateur). */
class CliniqueService extends Model
{
    protected $table = 'clinique_services';

    protected $fillable = ['service', 'modalite'];

    protected function casts(): array
    {
        return [
            'service' => ServiceClinique::class,
            'modalite' => ModaliteUrgences::class,
        ];
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }
}
