<?php

namespace App\Models;

use App\Enums\Espece;
use App\Enums\Sexe;
use App\Models\Concerns\AppartientAUneOrganisation;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use AppartientAUneOrganisation, HasFactory;

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

    /**
     * Dénormalisations de confort portées par les rendez-vous et les
     * files d'attente — réalignées sur la fiche dès qu'elle est
     * référencée : la FK fait foi (docs/data-model.md).
     *
     * @return array{client_id: int, patient_name: string, species: Espece, owner_name: string}
     */
    public function denormalisation(): array
    {
        return [
            'client_id' => $this->client_id,
            'patient_name' => $this->nom,
            'species' => $this->espece,
            'owner_name' => $this->client->nom_affiche,
        ];
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

    /** Demandes d'analyses labo — scopées clinique : la fiche patient
     * les lit toutes cliniques via withoutGlobalScope('clinique').
     *
     * @return HasMany<Analyse, $this> */
    public function analyses(): HasMany
    {
        return $this->hasMany(Analyse::class);
    }

    /** Examens d'imagerie — scopés clinique : la fiche patient les lit
     * toutes cliniques via withoutGlobalScope('clinique').
     *
     * @return HasMany<ExamenImagerie, $this> */
    public function examensImagerie(): HasMany
    {
        return $this->hasMany(ExamenImagerie::class);
    }

    /** Alerte « solde » (€) — dérivée de la dette, jamais stockée. */
    public function aSoldeDu(): bool
    {
        return (float) $this->dette > 0;
    }
}
