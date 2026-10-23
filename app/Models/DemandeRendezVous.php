<?php

namespace App\Models;

use App\Enums\DecisionAnnulation;
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
        'rendez_vous_id', 'traitee_le', 'jeton', 'acompte_centimes',
        'acompte_intention_id', 'acompte_paye_le', 'acompte_rembourse_le',
        'acompte_frais_centimes', 'acompte_rembourse_centimes', 'annulation_demandee_le',
        'annulation_motif', 'annulation_decision', 'annulation_traitee_le',
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
            'acompte_centimes' => 'integer',
            'acompte_paye_le' => 'immutable_datetime',
            'acompte_rembourse_le' => 'immutable_datetime',
            'acompte_frais_centimes' => 'integer',
            'acompte_rembourse_centimes' => 'integer',
            'annulation_demandee_le' => 'immutable_datetime',
            'annulation_decision' => DecisionAnnulation::class,
            'annulation_traitee_le' => 'immutable_datetime',
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

    /** Acompte payé et pas remboursé : en francs, sinon 0. */
    public function acompteRetenu(): float
    {
        if (! $this->acompte_paye_le) {
            return 0.0;
        }
        $rendu = $this->acompte_rembourse_le ? ($this->acompte_rembourse_centimes ?? $this->acompte_centimes) : 0;

        return round(max(0, ($this->acompte_centimes ?? 0) - $rendu) / 100, 2);
    }

    /** Annulation demandée par le propriétaire, pas encore décidée par la clinique. */
    public function annulationEnAttente(): bool
    {
        return $this->annulation_demandee_le !== null && $this->annulation_traitee_le === null;
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

    /** Retour du paiement de l'acompte (TWINT et banques qui redirigent) : la page de la demande. */
    public function lienDeRetourPaiement(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/demande/'.$this->jeton.'?paiement=1';
    }
}
