<?php

namespace App\Models;

use App\Enums\Espece;
use App\Models\Concerns\AppartientAUneOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un groupe d'animaux de rente d'une exploitation (09.11.2026) : « Vaches
 * laitières », 60 têtes. Un animal soigné seul y a son dossier, avec sa
 * marque auriculaire. Niveau organisation, comme son exploitation.
 */
class Herd extends Model
{
    use AppartientAUneOrganisation;

    /** Les espèces de rente d'un groupe */
    public const array SPECIES = [Espece::Bovin, Espece::Ovin, Espece::Caprin, Espece::Porc, Espece::Volaille, Espece::Cheval, Espece::Lapin];

    protected $fillable = ['organisation_id', 'client_id', 'name', 'species', 'head_count', 'archived_at'];

    protected function casts(): array
    {
        return ['species' => Espece::class, 'head_count' => 'integer', 'archived_at' => 'datetime'];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return HasMany<Patient, $this> */
    public function animals(): HasMany
    {
        return $this->hasMany(Patient::class);
    }
}
