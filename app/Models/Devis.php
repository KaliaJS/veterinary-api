<?php

namespace App\Models;

use App\Enums\CanalDevis;
use App\Enums\StatutDevis;
use App\Enums\VatContext;
use App\Models\Concerns\AppartientAUneOrganisation;
use App\Services\Facturation\LineVat;
use App\Support\DocumentHeader;
use App\Support\VatBreakdown;
use Database\Factories\DevisFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Devis — NIVEAU ORGANISATION ; patient et lignes facultatifs dans un
 * brouillon, exigés à l'envoi (01.11.2026). Cycle : brouillon
 * (seul modifiable et supprimable) → envoyé (canal selon le compte du
 * propriétaire) → accepté / refusé ; `expire` est DÉRIVÉ d'un envoyé
 * hors validité, jamais stocké. Remise en pourcentage, totaux dérivés.
 */
class Devis extends Model
{
    /** @use HasFactory<DevisFactory> */
    use AppartientAUneOrganisation, HasFactory;

    protected $table = 'devis';

    protected $fillable = [
        'organisation_id', 'clinique_id', 'numero', 'emis_le', 'patient_id', 'motif',
        'remise_pct', 'alea_pct', 'validite_jours', 'valide_jusquau',
        'acompte_pct', 'conditions', 'statut', 'canal', 'envoye_le', 'relance_le', 'signe_le',
        'signe_par', 'refuse_le', 'refuse_par', 'motif_refus', 'acompte_facture_id', 'facture_finale_id',
    ];

    /** Miroir des défauts de la base. */
    protected $attributes = [
        'statut' => 'brouillon',
        'remise_pct' => 0,
        'alea_pct' => 0,
        'validite_jours' => 30,
        'acompte_pct' => 0,
    ];

    protected function casts(): array
    {
        return [
            'emis_le' => 'date',
            'remise_pct' => 'integer',
            'alea_pct' => 'integer',
            'validite_jours' => 'integer',
            'valide_jusquau' => 'date',
            'acompte_pct' => 'integer',
            'statut' => StatutDevis::class,
            'canal' => CanalDevis::class,
            'envoye_le' => 'datetime',
            'relance_le' => 'datetime',
            'signe_le' => 'datetime',
            'refuse_le' => 'datetime',
            'recipient' => 'array',
            'issuer' => 'array',
        ];
    }

    /**
     * L'en-tête se fige quand le devis quitte le brouillon (envoyé, ou
     * accepté d'emblée) : le client et la clinique de ce jour (02.11.2026).
     * Un brouillon suit encore la fiche.
     */
    protected static function booted(): void
    {
        static::saving(function (Devis $devis): void {
            if ($devis->statut === StatutDevis::Brouillon || $devis->issuer !== null) {
                return;
            }
            $patient = $devis->patient_id === null ? null : Patient::withoutGlobalScopes()->find($devis->patient_id);
            $devis->issuer = DocumentHeader::issuer(Clinique::withoutGlobalScopes()->with('societe')->find($devis->clinique_id));
            $devis->recipient = DocumentHeader::recipient($patient?->client_id === null ? null : Client::withoutGlobalScopes()->find($patient->client_id));
        });
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /**
     * La clinique qui établit le devis (en-tête des pièces).
     *
     * @return BelongsTo<Clinique, $this>
     */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return HasMany<DevisLigne, $this> */
    public function lignes(): HasMany
    {
        return $this->hasMany(DevisLigne::class);
    }

    /** @return BelongsTo<Facture, $this> */
    public function acompteFacture(): BelongsTo
    {
        return $this->belongsTo(Facture::class, 'acompte_facture_id');
    }

    /** @return BelongsTo<Facture, $this> */
    public function factureFinale(): BelongsTo
    {
        return $this->belongsTo(Facture::class, 'facture_finale_id');
    }

    public function sousTotal(): float
    {
        $lignes = $this->relationLoaded('lignes') ? $this->lignes : $this->lignes()->get();

        return round((float) $lignes->sum(fn (DevisLigne $ligne): float => $ligne->quantite * (float) $ligne->prix_chf), 2);
    }

    /** Total TTC après remise commerciale. */
    public function totalTtc(): float
    {
        return round($this->sousTotal() * (1 - $this->remise_pct / 100), 2);
    }

    /**
     * La TVA par taux, comme sur la facture finale (03.11.2026) : le soin et
     * ce qui y est donné au taux du soin, un médicament remis pour la maison
     * à son taux de vente, la remise répartie au prorata des taux. Vide pour
     * une société non assujettie.
     *
     * @return list<array{rate: float, excl_vat: float, vat: float, incl_vat: float}>
     */
    public function vatBreakdown(): array
    {
        if ($this->clinique?->societe?->assujettie_tva === false) {
            return [];
        }
        $lignes = $this->relationLoaded('lignes') ? $this->lignes : $this->lignes()->get();
        $soin = $this->tauxTva();
        $produits = Produit::withoutGlobalScopes()->whereIn('id', $lignes->pluck('produit_id')->filter())->get()->keyBy('id');

        return VatBreakdown::of([
            ...$lignes->map(fn (DevisLigne $ligne): array => [
                'rate' => $ligne->vat_context === VatContext::Sale ? LineVat::saleRate($produits->get($ligne->produit_id)) : $soin,
                'amount' => $ligne->quantite * (float) $ligne->prix_chf,
            ])->all(),
            ['rate' => null, 'amount' => -$this->montantRemise()],
        ]);
    }

    /** TVA extraite du total TTC remisé : la somme de celle de chaque taux. */
    public function montantTva(): float
    {
        return VatBreakdown::vat($this->vatBreakdown());
    }

    /** Le taux de toutes ses lignes quand elles n'en ont qu'un, sinon null (la TVA se lit taux par taux). */
    public function tauxUnique(): ?float
    {
        $taux = array_column($this->vatBreakdown(), 'rate');

        return match (count($taux)) {
            0 => $this->clinique?->societe?->assujettie_tva === false ? 0.0 : $this->tauxTva(),
            1 => $taux[0],
            default => null,
        };
    }

    /**
     * Taux de TVA, en % : un devis chiffre un soin, ses articles donnés
     * pendant le soin ; il suit donc l'espèce de l'animal, comme les lignes
     * de sa facture finale (03.11.2026). 0 si la société n'est pas assujettie.
     */
    public function tauxTva(): float
    {
        if ($this->clinique?->societe?->assujettie_tva === false) {
            return 0.0;
        }
        $patient = $this->relationLoaded('patient') && $this->patient !== null
            ? $this->patient
            : Patient::withoutGlobalScopes()->find($this->patient_id);

        return LineVat::careRate($patient?->espece);
    }

    /** Total hors taxe du devis remisé. */
    public function totalHt(): float
    {
        return round($this->totalTtc() - $this->montantTva(), 2);
    }

    /** Haut de la fourchette quand des aléas sont annoncés. */
    public function totalAvecAlea(): float
    {
        return round($this->totalTtc() * (1 + $this->alea_pct / 100), 2);
    }

    /** Montant de la remise commerciale. */
    public function montantRemise(): float
    {
        return round($this->sousTotal() - $this->totalTtc(), 2);
    }

    public function montantAcompte(): float
    {
        return round($this->totalTtc() * $this->acompte_pct / 100, 2);
    }

    /** Un envoyé dont la validité est passée est expiré — dérivé. */
    public function estExpire(): bool
    {
        return $this->statut === StatutDevis::Envoye
            && $this->valide_jusquau !== null
            && $this->valide_jusquau->isBefore(aujourdhui());
    }

    public function statutEffectif(): string
    {
        return $this->estExpire() ? StatutDevis::EXPIRE : $this->statut->value;
    }
}
