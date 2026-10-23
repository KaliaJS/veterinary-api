<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneClinique;
use Database\Factories\MouvementStupefiantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Ligne du registre des stupéfiants (art. 62 OCStup) — immuable
 * (UPDATED_AT null, comme les audits et le journal de stock) : jamais
 * modifiée ni supprimée, une erreur se corrige par un mouvement
 * inverse. Le solde d'une substance est la somme des mouvements de la
 * clinique ; le patient est une vraie clé étrangère, obligatoire pour
 * une sortie.
 */
class MouvementStupefiant extends Model
{
    /** @use HasFactory<MouvementStupefiantFactory> */
    use AppartientAUneClinique, HasFactory;

    public const null UPDATED_AT = null;

    /** Seuil d'alerte du front (tools.js) : « STOCK BAS » sous 10 ml. */
    public const float SEUIL_STOCK_BAS = 10.0;

    protected $table = 'mouvements_stupefiants';

    protected $fillable = [
        'clinique_id', 'produit_id', 'patient_id', 'employe_id',
        'quantite', 'motif', 'created_at',
    ];

    /** Immuable : ni modification ni suppression, même hors de l'API */
    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Le registre des stupéfiants est immuable : corrigez par un mouvement inverse.'));
        static::deleting(fn (): never => throw new LogicException('Le registre des stupéfiants est immuable : corrigez par un mouvement inverse.'));
    }

    protected function casts(): array
    {
        return [
            'quantite' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }

    /** @return BelongsTo<Produit, $this> */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** Signataire du mouvement. @return BelongsTo<Employe, $this> */
    public function employe(): BelongsTo
    {
        return $this->belongsTo(Employe::class);
    }

    public function estUneSortie(): bool
    {
        return (float) $this->quantite < 0;
    }
}
