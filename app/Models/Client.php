<?php

namespace App\Models;

use App\Enums\Civilite;
use App\Enums\StatutRendezVous;
use App\Models\Concerns\AppartientAUneOrganisation;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use AppartientAUneOrganisation, HasFactory;

    protected $fillable = [
        'organisation_id', 'user_id', 'civilite', 'prenom', 'nom',
        'tel', 'email', 'rue', 'npa', 'localite', 'rappels_sms',
    ];

    protected function casts(): array
    {
        return [
            'civilite' => Civilite::class,
            'rappels_email' => 'boolean',
            'rappels_sms' => 'boolean',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** Compte espace client (0..1) — conditionne le canal des devis.
     * @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Patient, $this> */
    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }

    /**
     * Animaux du foyer par nom, pour la liste des propriétaires (qui
     * n'affiche que les noms) — la fiche charge `patients` en entier.
     *
     * @return HasMany<Patient, $this>
     */
    public function patientsParNom(): HasMany
    {
        return $this->hasMany(Patient::class)->orderBy('nom')->orderBy('id');
    }

    /** @return HasMany<RendezVous, $this> */
    public function rendezVous(): HasMany
    {
        return $this->hasMany(RendezVous::class);
    }

    /**
     * « Mme Lefèvre » — dénormalisation affichée par le planning.
     *
     * @return Attribute<string, never>
     */
    protected function nomAffiche(): Attribute
    {
        return Attribute::get(fn (): string => trim(($this->civilite->value ?? '').' '.$this->nom));
    }

    /** Compteur no-show dérivé des RDV (badge « NO-SHOW ×N »). */
    public function nombreNoShows(): int
    {
        return $this->rendezVous()->where('status', StatutRendezVous::Noshow)->count();
    }
}
