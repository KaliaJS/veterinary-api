<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneOrganisation;
use Database\Factories\PanelLaboFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Panel du référentiel d'analyses (LAB_PANELS du front) — PAR
 * ORGANISATION, créé à sa naissance via PanelsLaboParDefaut. Porte le
 * tube de prélèvement ; ses paramètres portent les bornes de
 * référence, instantanées dans chaque demande.
 */
class PanelLabo extends Model
{
    /** @use HasFactory<PanelLaboFactory> */
    use AppartientAUneOrganisation, HasFactory;

    protected $table = 'panels_labo';

    protected $fillable = ['organisation_id', 'code', 'nom', 'tube'];

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return HasMany<PanelParametre, $this> */
    public function parametres(): HasMany
    {
        return $this->hasMany(PanelParametre::class)->orderBy('position');
    }
}
