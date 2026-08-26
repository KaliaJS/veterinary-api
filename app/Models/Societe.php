<?php

namespace App\Models;

use Database\Factories\SocieteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Societe extends Model
{
    /** @use HasFactory<SocieteFactory> */
    use HasFactory;

    protected $fillable = ['organisation_id', 'nom', 'ide'];

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return HasMany<Clinique, $this> */
    public function cliniques(): HasMany
    {
        return $this->hasMany(Clinique::class);
    }
}
