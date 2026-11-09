<?php

namespace App\Models;

use App\Enums\Espece;
use App\Enums\MoyenPaiement;
use App\Enums\SettlementMethod;
use App\Enums\StatutFacture;
use App\Enums\StatutPaiement;
use App\Enums\TypeLigneFacture;
use App\Enums\VatContext;
use App\Enums\VatRate;
use App\Models\Concerns\AppartientAUneOrganisation;
use App\Services\Facturation\PatientDebts;
use App\Services\Facturation\Settlements;
use App\Support\DocumentHeader;
use App\Support\VatBreakdown;
use Database\Factories\FactureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * La facture suit le patient — NIVEAU ORGANISATION — et garde la
 * clinique d'émission : l'encaissement, les lignes et la clôture de
 * caisse s'y font. Le total est dérivé des lignes, la TVA extraite taux
 * par taux, chaque ligne au sien (03.11.2026) ; jamais de suppression
 * hors fenêtre d'annulation d'une création.
 *
 * @property-read bool|null $paye_par_stripe  encaissée par Stripe (`withExists` de la liste des factures)
 */
class Facture extends Model
{
    /** @use HasFactory<FactureFactory> */
    use AppartientAUneOrganisation, HasFactory;

    protected $fillable = [
        'organisation_id', 'clinique_id', 'numero', 'emise_le', 'patient_id',
        'client_id', 'patient_name', 'species', 'owner_name', 'ligne_planning_id',
        'rendez_vous_id', 'ordonnance_id', 'statut', 'moyen_paiement', 'payee_le',
        'stock_sorti', 'cloture_caisse_id', 'cree_par_id', 'encaissee_par_id', 'acompte', 'taux_tva',
    ];

    /**
     * Le taux de TVA se fige à l'émission, selon la société de la clinique
     * (23.10.2026) : le taux normal, 0 hors assujettissement. Depuis le
     * 03.11.2026, chaque ligne a le sien ; celui de la facture dit si la
     * société était assujettie, et reste le taux des lignes d'avant.
     */
    protected static function booted(): void
    {
        static::creating(function (Facture $facture): void {
            if (! isset($facture->getAttributes()['taux_tva'])) {
                $facture->taux_tva = Clinique::query()->with('societe')->find($facture->clinique_id)?->societe?->tauxTva() ?? VatRate::Normal->percent();
            }
            // L'en-tête se fige à l'émission : le client et la clinique de ce jour (02.11.2026)
            if (! isset($facture->getAttributes()['issuer'])) {
                $facture->issuer = DocumentHeader::issuer(Clinique::withoutGlobalScopes()->with('societe')->find($facture->clinique_id));
                $facture->recipient = DocumentHeader::recipient($facture->client_id === null ? null : Client::withoutGlobalScopes()->find($facture->client_id));
            }
        });
        // L'acompte payé en ligne à la réservation est son premier règlement, daté de son paiement (09.11.2026)
        static::created(function (Facture $facture): void {
            if ((float) $facture->acompte > 0) {
                app(Settlements::class)->recordDeposit($facture);
            }
        });
        // Émise, payée, annulée, passée à un autre animal : la dette de l'animal suit (03.11.2026)
        static::saved(function (Facture $facture): void {
            if ($facture->wasRecentlyCreated || $facture->wasChanged(['statut', 'acompte', 'patient_id'])) {
                PatientDebts::refresh([$facture->patient_id, $facture->getOriginal('patient_id')]);
            }
        });
        static::deleted(fn (Facture $facture) => PatientDebts::refresh([$facture->patient_id]));
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
            'vente_comptoir' => 'boolean',
            'annulee_le' => 'datetime',
            'moyen_paiement' => MoyenPaiement::class,
            'payee_le' => 'datetime',
            'stock_sorti' => 'boolean',
            'acompte' => 'decimal:2',
            'taux_tva' => 'float',
            'recipient' => 'array',
            'issuer' => 'array',
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

    /**
     * Ses règlements (09.11.2026) : paiements reçus, acompte en ligne,
     * annulations et remboursements (négatifs), dans l'ordre.
     *
     * @return HasMany<Settlement, $this>
     */
    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class)->withoutGlobalScopes()->orderBy('received_on')->orderBy('id');
    }

    /** Ce qui est réglé : la somme de ses règlements, acompte en ligne compris. */
    public function montantRegle(): float
    {
        $lignes = $this->relationLoaded('settlements') ? $this->settlements : $this->settlements()->get();

        return round((float) $lignes->sum(fn (Settlement $s): float => (float) $s->amount), 2);
    }

    /** Ce qui reste à encaisser : le total moins ses règlements (l'acompte payé en ligne en est un, 09.11.2026). */
    public function resteAPayer(): float
    {
        return max(0.0, round($this->totalTtc() - $this->montantRegle(), 2));
    }

    /**
     * A reçu au moins un paiement (caisse ou virement), même annulé depuis :
     * le journal le garde, la facture ne s'efface ni ne s'annule plus
     * (vérification du 09.11.2026).
     */
    public function aRecuUnReglement(): bool
    {
        return $this->settlements()->where('method', '!=', SettlementMethod::Acompte->value)->exists();
    }

    /** Due avec déjà un paiement reçu à la caisse ou par virement (pas seulement l'acompte en ligne). */
    public function estPayeeEnPartie(): bool
    {
        $lignes = $this->relationLoaded('settlements') ? $this->settlements : $this->settlements()->get();

        return $this->estDue() && round((float) $lignes->filter(fn (Settlement $s): bool => $s->method !== SettlementMethod::Acompte)->sum(fn (Settlement $s): float => (float) $s->amount), 2) > 0;
    }

    /**
     * La TVA par taux : hors taxe, TVA et TTC de chaque taux de ses lignes
     * (03.11.2026). Vide pour une société non assujettie.
     *
     * @return list<array{rate: float, excl_vat: float, vat: float, incl_vat: float}>
     */
    public function vatBreakdown(): array
    {
        $lignes = $this->relationLoaded('lignes') ? $this->lignes : $this->lignes()->get();

        return VatBreakdown::of($lignes->map(fn (FactureLigne $ligne): array => [
            'rate' => $ligne->vatRate($this),
            'amount' => $ligne->quantite * (float) $ligne->prix_chf,
            // Une part d'acompte garde la TVA arrondie de sa facture d'acompte (04.11.2026)
            'separate' => $ligne->vat_context === VatContext::Deposit,
        ]));
    }

    /**
     * La facture contient un soin (un acte, même offert) : ses articles peuvent y
     * être donnés pendant le soin, au taux du soin (vérification du 03.11.2026).
     */
    public function hasCare(): bool
    {
        $lignes = $this->relationLoaded('lignes') ? $this->lignes : $this->lignes()->get();

        // Un acte, même offert (0 CHF) ; jamais une remise ni une part d'acompte (vérification du 04.11.2026)
        return $lignes->contains(fn (FactureLigne $ligne): bool => $ligne->type === TypeLigneFacture::Acte
            && (float) $ligne->prix_chf >= 0
            && ! in_array($ligne->vat_context, [VatContext::Discount, VatContext::Deposit], true));
    }

    /** La TVA contenue dans le total : la somme de celle de chaque taux. */
    public function montantTva(): float
    {
        return VatBreakdown::vat($this->vatBreakdown());
    }

    /**
     * Le taux de toutes ses lignes quand elles n'en ont qu'un, sinon null
     * (plusieurs taux : la TVA se lit taux par taux). Sans ligne, celui de
     * la facture.
     */
    public function tauxUnique(): ?float
    {
        $lignes = $this->relationLoaded('lignes') ? $this->lignes : $this->lignes()->get();
        // Une remise n'a pas de taux : elle se répartit sur ceux des autres lignes
        $taux = $lignes->map(fn (FactureLigne $ligne): ?float => $ligne->vatRate($this))->filter(fn (?float $t): bool => $t !== null)->unique()->values();

        return match ($taux->count()) {
            0 => (float) $this->taux_tva,
            1 => (float) $taux->first(),
            default => null,
        };
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
