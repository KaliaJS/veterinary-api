<?php

namespace App\Models;

use App\Enums\DecisionAnnulation;
use App\Enums\Espece;
use App\Enums\ModeDemandeRendezVous;
use App\Enums\MotifRefusDemande;
use App\Enums\StatutDemandeRendezVous;
use App\Models\Concerns\AppartientAUneClinique;
use App\Models\Concerns\ChangeLesFilesDuPlanning;
use App\Support\TempsReel;
use Carbon\CarbonImmutable;
use Database\Factories\DemandeRendezVousFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * Demande de rendez-vous en ligne (espace client, site de la clinique,
 * sans compte) : l'heure choisie dans le calendrier, puis la réponse de
 * la clinique (statut). Le propriétaire en suit l'issue dans son espace,
 * ou par le lien de son e-mail s'il n'a pas de compte (`jeton`).
 */
class DemandeRendezVous extends Model
{
    /** @use HasFactory<DemandeRendezVousFactory> */
    use AppartientAUneClinique, ChangeLesFilesDuPlanning, HasFactory;

    protected $table = 'demandes_rendez_vous';

    protected $fillable = [
        'clinique_id', 'patient_id', 'client_id', 'user_id', 'patient_name',
        'species', 'owner_name', 'email', 'telephone', 'reason', 'urgence',
        'symptomes', 'souhait', 'debut_souhaite', 'teleconsultation', 'mode',
        'duree_minutes', 'statut', 'creneaux_proposes', 'motif_refus', 'message',
        'rendez_vous_id', 'report_de_id', 'traitee_le', 'jeton', 'acompte_centimes',
        'acompte_intention_id', 'acompte_stripe_compte_id', 'acompte_paye_le', 'acompte_rembourse_le',
        'acompte_frais_centimes', 'acompte_rembourse_centimes', 'acompte_litige', 'acompte_litige_echeance', 'annulation_demandee_le',
        'annulation_motif', 'annulation_decision', 'annulation_traitee_le',
    ];

    protected $hidden = ['jeton', 'jeton_chiffre'];

    /** Jours pendant lesquels le lien sert encore après l'heure demandée. */
    public const int JOURS_LIEN = 30;

    /** Une demande neuve attend la réponse de la clinique. */
    protected $attributes = ['statut' => 'a_traiter', 'teleconsultation' => false, 'urgence' => false];

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
            'jeton_chiffre' => 'encrypted',
            'jeton_expire_le' => 'immutable_datetime',
            'acompte_rembourse_le' => 'immutable_datetime',
            'acompte_frais_centimes' => 'integer',
            'acompte_rembourse_centimes' => 'integer',
            'acompte_litige_echeance' => 'immutable_datetime',
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

    /**
     * Un acompte payé dont il reste de l'argent, qu'aucune décision
     * d'annulation n'a tranché : ce qu'il devient se décide (droit
     * « Rembourser »). Un rendu partiel chez Stripe n'y change rien
     * (24.10.2026) : le reste est encore en jeu.
     */
    public function acompteEnJeu(): bool
    {
        return $this->acompte_intention_id !== null
            && $this->acompteRestantCentimes() > 0
            && ! ($this->annulation_decision?->annule() ?? false);
    }

    /** Centimes de l'acompte payé pas encore rendus (0 sans paiement). */
    public function acompteRestantCentimes(): int
    {
        if (! $this->acompte_paye_le) {
            return 0;
        }
        $rendu = $this->acompte_rembourse_le ? (int) ($this->acompte_rembourse_centimes ?? $this->acompte_centimes) : 0;

        return max(0, (int) $this->acompte_centimes - $rendu);
    }

    /** L'acompte payé, moins ce qui en a été rendu : en francs, sinon 0. */
    public function acompteRetenu(): float
    {
        return round($this->acompteRestantCentimes() / 100, 2);
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

    /**
     * Le lien secret d'une demande (23.10.2026) : seule son empreinte sert
     * à la retrouver (`jeton`), le lien lui-même est gardé chiffré pour
     * les e-mails suivants ; il ne sert plus 30 jours après l'heure
     * demandée.
     */
    protected function jeton(): Attribute
    {
        return Attribute::make(set: fn (?string $clair) => $clair === null
            ? ['jeton' => null, 'jeton_chiffre' => null]
            : ['jeton' => self::empreinte($clair), 'jeton_chiffre' => Crypt::encryptString($clair)]);
    }

    protected static function booted(): void
    {
        // Alertes en temps réel (28.10.2026) : acompte contesté, litige clos, acompte rendu
        static::saved(function (DemandeRendezVous $demande): void {
            if ($demande->wasChanged(['acompte_litige', 'acompte_rembourse_le', 'acompte_rembourse_centimes'])) {
                TempsReel::alertes((int) $demande->clinique_id);
            }
        });
        // L'échéance du lien suit l'heure demandée, connue à l'enregistrement
        static::saving(function (DemandeRendezVous $demande): void {
            // L'empreinte enregistrée (l'attribut ne se relit pas : il ne fait qu'écrire)
            $empreinte = $demande->getAttributes()['jeton'] ?? null;
            if ($demande->isDirty('jeton') || ($empreinte !== null && $demande->isDirty('debut_souhaite'))) {
                $demande->jeton_expire_le = $empreinte === null
                    ? null
                    : CarbonImmutable::parse($demande->debut_souhaite ?? now())->max(now())->addDays(self::JOURS_LIEN);
            }
        });
    }

    public static function empreinte(string $clair): string
    {
        return hash('sha256', $clair);
    }

    /** Le lien en clair, pour l'écrire dans un e-mail ou le rendre au navigateur qui l'a reçu. */
    public function jetonClair(): ?string
    {
        return $this->jeton_chiffre;
    }

    public function lienEstExpire(): bool
    {
        return $this->jeton_expire_le !== null && $this->jeton_expire_le->isPast();
    }

    /** Où le propriétaire suit sa demande : son espace, ou le lien de la demande sans compte. */
    public function lienDeSuivi(): string
    {
        $front = rtrim((string) config('app.frontend_url'), '/');

        return $this->user_id === null && $this->jetonClair()
            ? $front.'/demande/'.$this->jetonClair()
            : $front.'/espace/rendez-vous';
    }

    /** Retour du paiement de l'acompte (TWINT et banques qui redirigent) : la page de la demande. */
    public function lienDeRetourPaiement(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/demande/'.$this->jetonClair().'?paiement=1';
    }
}
