<?php

namespace App\Models;

use App\Enums\Espece;
use App\Enums\MoyenPaiement;
use App\Enums\StatutFacture;
use App\Enums\StatutPaiement;
use App\Models\Concerns\AppartientAUneOrganisation;
use Database\Factories\FactureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * La facture suit le patient — NIVEAU ORGANISATION — et garde la
 * clinique d'émission : l'encaissement, les lignes et la clôture de
 * caisse s'y font. Le total est dérivé des lignes, la TVA extraite au
 * taux normal 8.1 % ; jamais de suppression hors fenêtre d'annulation
 * d'une création.
 *
 * @property-read bool|null $paye_par_stripe  encaissée par Stripe (`withExists` de la liste des factures)
 */
class Facture extends Model
{
    /** @use HasFactory<FactureFactory> */
    use AppartientAUneOrganisation, HasFactory;

    /** Taux normal suisse — le prix des lignes est TTC. */
    public const float TAUX_TVA = 0.081;

    protected $fillable = [
        'organisation_id', 'clinique_id', 'numero', 'emise_le', 'patient_id',
        'client_id', 'patient_name', 'species', 'owner_name', 'ligne_planning_id',
        'rendez_vous_id', 'ordonnance_id', 'statut', 'moyen_paiement', 'payee_le',
        'stock_sorti', 'cloture_caisse_id', 'cree_par_id', 'encaissee_par_id', 'acompte', 'taux_tva',
    ];

    /** Le taux de TVA se fige à l'émission, selon la société de la clinique (23.10.2026). */
    protected static function booted(): void
    {
        static::creating(function (Facture $facture): void {
            if (! isset($facture->getAttributes()['taux_tva'])) {
                $facture->taux_tva = Clinique::query()->with('societe')->find($facture->clinique_id)?->societe?->tauxTva() ?? self::TAUX_TVA * 100;
            }
        });
    }

    /** Miroir des défauts de la base. */
    protected $attributes = [
        'statut' => 'due',
        'stock_sorti' => false,
    ];

    protected function casts(): array
    {
        return [
            'emise_le' => 'datetime',
            'species' => Espece::class,
            'statut' => StatutFacture::class,
            'moyen_paiement' => MoyenPaiement::class,
            'payee_le' => 'datetime',
            'stock_sorti' => 'boolean',
            'acompte' => 'decimal:2',
            'taux_tva' => 'float',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }

    /**
     * Qui a encaissé (caisse, terminal, vente comptoir).
     *
     * @return BelongsTo<User, $this>
     */
    public function encaisseePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'encaissee_par_id');
    }

    /** Statuts d'un paiement par terminal qui prouve l'encaissement : un paiement rendu n'a rien apporté (24.10.2026). */
    public static function preuvesDeTerminal(): array
    {
        return [StatutPaiement::Reussi, StatutPaiement::Litige];
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

    /** @return BelongsTo<RendezVous, $this> */
    public function rendezVous(): BelongsTo
    {
        return $this->belongsTo(RendezVous::class, 'rendez_vous_id');
    }

    /** @return BelongsTo<Ordonnance, $this> */
    public function ordonnance(): BelongsTo
    {
        return $this->belongsTo(Ordonnance::class);
    }

    /** @return BelongsTo<ClotureCaisse, $this> */
    public function clotureCaisse(): BelongsTo
    {
        return $this->belongsTo(ClotureCaisse::class);
    }

    /** @return HasMany<FactureLigne, $this> */
    public function lignes(): HasMany
    {
        return $this->hasMany(FactureLigne::class);
    }

    /** @return HasMany<FacturePrescription, $this> */
    public function prescriptions(): HasMany
    {
        return $this->hasMany(FacturePrescription::class);
    }

    /** Tentatives de paiement Stripe (TPE, TWINT).
     * @return HasMany<Paiement, $this> */
    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class);
    }

    /** @return HasOne<DecompteAssurance, $this> */
    public function decompte(): HasOne
    {
        return $this->hasOne(DecompteAssurance::class);
    }

    /** Total TTC dérivé — jamais stocké (docs/data-model.md §6). */
    public function totalTtc(): float
    {
        $lignes = $this->relationLoaded('lignes') ? $this->lignes : $this->lignes()->get();

        return round((float) $lignes->sum(fn (FactureLigne $ligne): float => $ligne->quantite * (float) $ligne->prix_chf), 2);
    }

    /** Ce qui reste à encaisser : le total moins l'acompte payé en ligne à la réservation. */
    public function resteAPayer(): float
    {
        return max(0.0, round($this->totalTtc() - (float) $this->acompte, 2));
    }

    public function montantTva(): float
    {
        $total = $this->totalTtc();

        return round($total - $total / (1 + $this->taux_tva / 100), 2);
    }

    /** Total hors taxe : le TTC moins la TVA qu'il contient. */
    public function totalHt(): float
    {
        return round($this->totalTtc() - $this->montantTva(), 2);
    }

    /** Un paiement Stripe attend le client : la facture ne change plus d'ici là. */
    public function aUnPaiementEnCours(): bool
    {
        return $this->paiements()->where('statut', StatutPaiement::EnAttente)->exists();
    }

    public function estDue(): bool
    {
        return $this->statut === StatutFacture::Due;
    }
}
