<?php

namespace App\Models;

use App\Enums\AccesClinique;
use App\Enums\Espece;
use App\Enums\Sexe;
use App\Enums\StatutRendezVous;
use App\Models\Concerns\AppartientAUneOrganisation;
use App\Models\Concerns\AUnePhoto;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Patient extends Model
{
    public const int POINTS_COURBE_POIDS = 12;

    /** @use HasFactory<PatientFactory> */
    use AppartientAUneOrganisation, AUnePhoto, HasFactory;

    /** Identité de l animal : ce que le propriétaire peut réserver à sa main (AccesClinique) */
    public const CHAMPS_IDENTITE = ['nom', 'espece', 'race', 'sexe', 'ne_le', 'est_sterilise', 'puce'];

    /** Le défaut de la colonne, connu dès la création (sans relire la ligne) */
    protected $attributes = [
        'acces_clinique' => AccesClinique::Modification->value,
        'est_sterilise' => false,
        'est_agressif' => false,
        'a_allergies' => false,
        'rappels_coupes' => false,
    ];

    protected $fillable = [
        'organisation_id', 'client_id', 'proprietaire_id', 'acces_clinique', 'nom', 'espece', 'race', 'sexe',
        'ne_le', 'decede_le', 'est_sterilise', 'puce', 'photo_chemin', 'dette', 'est_agressif',
        'a_allergies', 'traitement_en_cours', 'rappels_coupes',
        'amicus_declare_le', 'client_depuis',
    ];

    protected function casts(): array
    {
        return [
            'espece' => Espece::class,
            'sexe' => Sexe::class,
            'acces_clinique' => AccesClinique::class,
            'ne_le' => 'date',
            'decede_le' => 'date',
            'est_sterilise' => 'boolean',
            'dette' => 'decimal:2',
            'est_agressif' => 'boolean',
            'a_allergies' => 'boolean',
            'rappels_coupes' => 'boolean',
            'amicus_declare_le' => 'datetime',
            'client_depuis' => 'integer',
        ];
    }

    /** URL publique de la photo (nom de fichier imprévisible), ou null */
    public function photoUrl(): ?string
    {
        return $this->photo_chemin ? route('patient.photo', ['fichier' => basename($this->photo_chemin)]) : null;
    }

    /** Fiche gérée par son propriétaire depuis l espace client (compte rattaché) */
    public function estGereParProprietaire(): bool
    {
        return $this->proprietaire_id !== null;
    }

    /** La clinique peut modifier l identité : fiche de clinique, ou droit « modification » accordé par le propriétaire */
    public function cliniquePeutModifierIdentite(): bool
    {
        return ! $this->estGereParProprietaire() || $this->acces_clinique !== AccesClinique::Lecture;
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
     * Compte espace client propriétaire (0..1) — indépendant de la
     * fiche client d'une clinique : un animal peut avoir l'un, l'autre
     * ou les deux (docs/data-model.md).
     *
     * @return BelongsTo<User, $this>
     */
    public function proprietaire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proprietaire_id');
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
            'owner_name' => $this->client->nom_affiche ?? $this->proprietaire->name ?? '',
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

    /**
     * Prochain rendez-vous à venir (au plus un, chargé en liste comme
     * en fiche) : toutes cliniques de l'organisation — le patient est
     * de niveau organisation, ses RDV aussi — hors annulés, no-shows
     * et finis.
     *
     * @return HasMany<RendezVous, $this>
     */
    public function rendezVousAVenir(): HasMany
    {
        return $this->hasMany(RendezVous::class)
            ->withoutGlobalScope('clinique')
            ->where('starts_at', '>=', now())
            ->whereIn('status', [StatutRendezVous::Prevu, StatutRendezVous::Salle, StatutRendezVous::Cours])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(1);
    }

    /**
     * Courbe de poids de la liste (sparkline) : les douze dernières
     * pesées, la plus récente d'abord — le dossier complet (show)
     * charge `pesees` en entier.
     *
     * @return HasMany<Pesee, $this>
     */
    public function courbePoids(): HasMany
    {
        return $this->hasMany(Pesee::class)
            ->orderByDesc('pesee_le')
            ->orderByDesc('id')
            ->limit(self::POINTS_COURBE_POIDS);
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
