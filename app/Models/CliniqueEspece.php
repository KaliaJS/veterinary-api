<?php

namespace App\Models;

use App\Enums\Espece;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une espèce soignée par une clinique (cochée par l'administrateur). */
class CliniqueEspece extends Model
{
    protected $table = 'clinique_especes';

    protected $fillable = ['espece'];

    protected function casts(): array
    {
        return ['espece' => Espece::class];
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }
}
