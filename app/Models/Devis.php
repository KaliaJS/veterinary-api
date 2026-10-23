<?php

namespace App\Models;

use App\Enums\CanalDevis;
use App\Enums\StatutDevis;
use App\Models\Concerns\AppartientAUneOrganisation;
use Database\Factories\DevisFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Devis — NIVEAU ORGANISATION, patient obligatoire. Cycle : brouillon
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
        'acompte_pct', 'conditions', 'statut', 'canal', 'signe_le',
        'signe_par', 'acompte_facture_id', 'facture_finale_id',
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
            'signe_le' => 'datetime',
        ];
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

    /** TVA extraite du total TTC remisé, au taux des factures. */
    public function montantTva(): float
    {
        $total = $this->totalTtc();

        return round($total - $total / (1 + $this->tauxTva() / 100), 2);
    }

    /** Taux de TVA, en % : celui de la société de la clinique qui l'établit. */
    public function tauxTva(): float
    {
        return $this->clinique?->societe?->tauxTva() ?? Facture::TAUX_TVA * 100;
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
