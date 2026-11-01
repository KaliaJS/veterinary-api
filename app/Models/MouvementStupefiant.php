<?php

namespace App\Models;

use App\Enums\TypeMouvementStupefiant;
use App\Models\Concerns\AppartientAUneClinique;
use Database\Factories\MouvementStupefiantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Ligne du registre des stupéfiants (art. 57, 63 et 64 OCStup ; tenu dix
 * ans, art. 62 al. 3) — immuable (UPDATED_AT null, comme les audits et le
 * journal de stock) : jamais modifiée ni supprimée, une erreur se corrige
 * par une ligne « correction » qui pointe la ligne fausse. Le stock d'une
 * substance est la somme des lignes de la clinique ; chaque ligne garde le
 * stock après elle et les noms figés à l'inscription (01.11.2026).
 */
class MouvementStupefiant extends Model
{
    /** @use HasFactory<MouvementStupefiantFactory> */
    use AppartientAUneClinique, HasFactory;

    public const null UPDATED_AT = null;

    protected $table = 'mouvements_stupefiants';

    protected $fillable = [
        'clinique_id', 'produit_id', 'patient_id', 'employe_id', 'type',
        'quantite', 'solde_apres', 'corrige_id', 'quantite_comptee', 'motif',
        'patient_nom', 'detenteur_nom', 'signataire_nom', 'ordonnance_numero',
        'fournisseur', 'bulletin', 'bulletin_le', 'created_at',
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
            'type' => TypeMouvementStupefiant::class,
            'quantite' => 'decimal:3',
            'solde_apres' => 'decimal:3',
            'quantite_comptee' => 'decimal:3',
            'bulletin_le' => 'date',
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

    /**
     * Signataire du mouvement.
     *
     * @return BelongsTo<Employe, $this>
     */
    public function employe(): BelongsTo
    {
        return $this->belongsTo(Employe::class);
    }

    /** La ligne qu'une correction corrige
     * @return BelongsTo<MouvementStupefiant, $this> */
    public function corrige(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrige_id');
    }

    public function estUneSortie(): bool
    {
        return (float) $this->quantite < 0;
    }
}
