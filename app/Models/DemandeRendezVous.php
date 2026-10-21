<?php

namespace App\Models;

use App\Enums\Espece;
use App\Enums\ModeDemandeRendezVous;
use App\Enums\MotifRefusDemande;
use App\Enums\StatutDemandeRendezVous;
use App\Models\Concerns\AppartientAUneClinique;
use Database\Factories\DemandeRendezVousFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande de rendez-vous en ligne (espace client, site de la clinique,
 * sans compte) : l'heure choisie dans le calendrier, puis la réponse de
 * la clinique (statut). Le propriétaire en suit l'issue dans son espace,
 * ou par le lien de son e-mail s'il n'a pas de compte (`jeton`).
 */
class DemandeRendezVous extends Model
{
    /** @use HasFactory<DemandeRendezVousFactory> */
    use AppartientAUneClinique, HasFactory;

    protected $table = 'demandes_rendez_vous';

    protected $fillable = [
        'clinique_id', 'patient_id', 'client_id', 'user_id', 'patient_name',
        'species', 'owner_name', 'email', 'telephone', 'reason', 'urgence',
        'symptomes', 'souhait', 'debut_souhaite', 'teleconsultation', 'mode',
        'duree_minutes', 'statut', 'creneaux_proposes', 'motif_refus', 'message',
        'rendez_vous_id', 'traitee_le', 'jeton',
    ];

    protected $hidden = ['jeton'];

    /** Une demande neuve attend la réponse de la clinique. */
    protected $attributes = ['statut' => 'a_traiter', 'teleconsultation' => false];

    protected function casts(): array
    {
        return [
            'species' => Espece::class,
            'mode' => ModeDemandeRendezVous::class,
            'statut' => StatutDemandeRendezVous::class,
            'motif_refus' => MotifRefusDemande::class,
            'urgence' => 'boolean',
            'teleconsultation' => 'boolean',
            'duree_minutes' => 'integer',
            'debut_souhaite' => 'immutable_datetime',
            'traitee_le' => 'immutable_datetime',
            'creneaux_proposes' => 'array',
        ];
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<RendezVous, $this> */
    public function rendezVous(): BelongsTo
    {
        return $this->belongsTo(RendezVous::class);
    }

    /** À qui écrire la réponse : le compte, sinon l'adresse laissée sans compte. */
    public function adresseDuProprietaire(): ?string
    {
        return $this->user?->email ?: $this->email;
    }

    /** Où le propriétaire suit sa demande : son espace, ou le lien de la demande sans compte. */
    public function lienDeSuivi(): string
    {
        $front = rtrim((string) config('app.frontend_url'), '/');

        return $this->user_id === null && $this->jeton
            ? $front.'/demande/'.$this->jeton
            : $front.'/espace/rendez-vous';
    }
}
