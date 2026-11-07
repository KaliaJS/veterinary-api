<?php

namespace App\Models;

use App\Enums\TypeMouvementStupefiant;
use App\Models\Concerns\AppartientAUneClinique;
use Carbon\CarbonImmutable;
use Database\Factories\MouvementStupefiantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
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
        'clinique_id', 'produit_id', 'lot_id', 'numero_lot', 'patient_id', 'employe_id', 'type',
        'quantite', 'solde_apres', 'corrige_id', 'premiere_ligne_id', 'quantite_comptee', 'motif',
        'patient_nom', 'detenteur_nom', 'signataire_nom', 'ordonnance_id', 'ordonnance_numero', 'ordonnance_le',
        'fournisseur', 'bulletin', 'bulletin_le', 'date_registre', 'created_at',
    ];

    /** Immuable : ni modification ni suppression, même hors de l'API */
    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Le registre des stupéfiants est immuable : corrigez par un mouvement inverse.'));
        static::deleting(fn (): never => throw new LogicException('Le registre des stupéfiants est immuable : corrigez par un mouvement inverse.'));
        // La date de la ligne, quand l'écriture ne la donne pas : le jour de l'inscription, à l'heure de la clinique
        static::creating(function (MouvementStupefiant $mouvement): void {
            if ($mouvement->date_registre === null) {
                $fuseau = Clinique::query()->find($mouvement->clinique_id)->fuseau_horaire ?? 'Europe/Zurich';
                $mouvement->date_registre = Carbon::parse(CarbonImmutable::parse($mouvement->created_at ?? now())->setTimezone($fuseau)->toDateString());
            }
        });
    }

    protected function casts(): array
    {
        return [
            'type' => TypeMouvementStupefiant::class,
            'quantite' => 'decimal:3',
            'solde_apres' => 'decimal:3',
            'quantite_comptee' => 'decimal:3',
            'bulletin_le' => 'date',
            'date_registre' => 'date',
            'ordonnance_le' => 'date',
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

    /**
     * Le lot inscrit sur la ligne (04.11.2026) : c'est de lui que le stock sort, ou à lui qu'il revient
     *
     * @return BelongsTo<Lot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
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
