<?php

namespace App\Models;

use App\Enums\TypeLigneFacture;
use App\Enums\VatContext;
use App\Services\Facturation\LineVat;
use App\Services\Facturation\PatientDebts;
use Database\Factories\FactureLigneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne de facture — sans scope propre : elle se résout toujours via sa
 * facture. Prix unitaire TTC signé (remise et acompte en négatif). Son
 * taux de TVA se calcule seul à sa naissance, selon son contexte, et se
 * fige (03.11.2026) ; une ligne d'avant n'en a pas et prend celui de sa
 * facture.
 */
class FactureLigne extends Model
{
    /** @use HasFactory<FactureLigneFactory> */
    use HasFactory;

    /** Le taux n'est jamais rempli de l'extérieur : seul le contexte se dit (soin ou vente). */
    protected $fillable = ['facture_id', 'libelle', 'quantite', 'prix_chf', 'type', 'produit_id', 'vat_context'];

    /** Une ligne ajoutée, changée ou retirée : la dette de l'animal suit (03.11.2026) */
    protected static function booted(): void
    {
        $refresh = function (FactureLigne $ligne): void {
            PatientDebts::refresh([Facture::withoutGlobalScopes()->whereKey($ligne->facture_id)->value('patient_id')]);
        };
        // Le taux naît avec la ligne, calculé seul, et ne bouge plus (TVA par ligne, 03.11.2026)
        static::creating(function (FactureLigne $ligne): void {
            $ligne->vat_context ??= LineVat::defaultContext($ligne);
            // Une ligne négative sans article, hors déduction d'acompte, est une remise répartie
            if ($ligne->vat_context !== VatContext::Deposit && $ligne->produit_id === null && (float) $ligne->prix_chf < 0) {
                $ligne->vat_context = VatContext::Discount;
            }
            $ligne->taux_tva = LineVat::forLine($ligne, Facture::withoutGlobalScopes()->findOrFail($ligne->facture_id));
        });
        static::saved($refresh);
        static::deleted($refresh);
    }

    protected function casts(): array
    {
        return [
            'quantite' => 'integer',
            'prix_chf' => 'decimal:2',
            'type' => TypeLigneFacture::class,
            'taux_tva' => 'float',
            'vat_context' => VatContext::class,
        ];
    }

    /**
     * Le taux de la ligne, en % : le sien, figé, ou celui de sa facture pour
     * une ligne d'avant ; null pour une remise, répartie au prorata des taux.
     */
    public function vatRate(Facture $facture): ?float
    {
        if ($this->vat_context === VatContext::Discount) {
            return null;
        }

        return (float) ($this->taux_tva ?? $facture->taux_tva);
    }

    /** Un article de la facture, dont le contexte (donné pendant le soin, remis) se dit en caisse. */
    public function isArticle(): bool
    {
        return $this->produit_id !== null && $this->type === TypeLigneFacture::Medicament;
    }

    /** @return BelongsTo<Facture, $this> */
    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    /** @return BelongsTo<Produit, $this> */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }
}
