<?php

namespace App\Models;

use App\Enums\Espece;
use App\Enums\Sexe;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use HasFactory;

    protected $fillable = [
        'organisation_id', 'client_id', 'nom', 'espece', 'race', 'sexe',
        'ne_le', 'est_sterilise', 'puce', 'dette', 'est_agressif',
        'a_allergies', 'traitement_en_cours', 'rappels_coupes',
        'amicus_declare_le', 'client_depuis',
    ];

    protected function casts(): array
    {
        return [
            'espece' => Espece::class,
            'sexe' => Sexe::class,
            'ne_le' => 'date',
            'est_sterilise' => 'boolean',
            'dette' => 'decimal:2',
            'est_agressif' => 'boolean',
            'a_allergies' => 'boolean',
            'rappels_coupes' => 'boolean',
            'amicus_declare_le' => 'datetime',
            'client_depuis' => 'integer',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return HasMany<Pesee, $this> */
    public function pesees(): HasMany
    {
        return $this->hasMany(Pesee::class);
    }

    /** @return HasMany<Vaccination, $this> */
    public function vaccinations(): HasMany
    {
        return $this->hasMany(Vaccination::class);
    }

    /** @return HasMany<Visite, $this> */
    public function visites(): HasMany
    {
        return $this->hasMany(Visite::class);
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** @return HasMany<ReleveOphtalmologique, $this> */
    public function relevesOphtalmologiques(): HasMany
    {
        return $this->hasMany(ReleveOphtalmologique::class);
    }

    /** @return HasMany<ReleveDentaire, $this> */
    public function relevesDentaires(): HasMany
    {
        return $this->hasMany(ReleveDentaire::class);
    }

    /** @return HasMany<RendezVous, $this> */
    public function rendezVous(): HasMany
    {
        return $this->hasMany(RendezVous::class);
    }

    /** Alerte « solde » (€) — dérivée de la dette, jamais stockée. */
    public function aSoldeDu(): bool
    {
        return (float) $this->dette > 0;
    }
}
