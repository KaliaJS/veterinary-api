<?php

namespace App\Models;

use Database\Factories\CompteRenduFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Compte rendu SOAP d'un rendez-vous (un seul par RDV — upsert). Signé,
 * il ne change plus (23.10.2026) : des compléments s'y ajoutent ; avant,
 * chaque modification garde la version remplacée.
 * Pas de scope propre : jamais résolu directement, toujours atteint
 * via son rendez-vous — résolu au NIVEAU ORGANISATION comme tout le
 * dossier médical (patient partagé entre les cliniques du jeton).
 */
class CompteRendu extends Model
{
    /** @use HasFactory<CompteRenduFactory> */
    use HasFactory;

    protected $table = 'comptes_rendus';

    protected $fillable = ['rendez_vous_id', 's', 'o', 'a', 'p', 'tool_notes', 'user_id', 'signe_le', 'signe_par_id'];

    protected function casts(): array
    {
        return ['signe_le' => 'datetime'];
    }

    /** Signé, le texte ne change plus, même hors de l'API */
    protected static function booted(): void
    {
        static::updating(function (CompteRendu $compteRendu): void {
            if ($compteRendu->getOriginal('signe_le') !== null && $compteRendu->isDirty(['s', 'o', 'a', 'p', 'tool_notes', 'signe_le', 'signe_par_id'])) {
                throw new LogicException('Un compte rendu signé ne change plus : ajoutez un complément.');
            }
        });
    }

    public function estSigne(): bool
    {
        return $this->signe_le !== null;
    }

    /** Rédigé : signé, ou un texte SOAP écrit ; des lignes notées seules n'en font pas un (04.11.2026) */
    public function estRedige(): bool
    {
        return $this->estSigne() || collect(['s', 'o', 'a', 'p'])->contains(fn (string $k): bool => trim((string) $this->{$k}) !== '');
    }

    /**
     * La même règle en requête.
     *
     * @param  Builder<CompteRendu>  $query
     * @return Builder<CompteRendu>
     */
    public function scopeRedige(Builder $query): Builder
    {
        // Des blancs seuls (espaces, retours à la ligne, tabulations) ne comptent
        // pas, comme dans estRedige ; REPLACE et TRIM, communs à MySQL et SQLite
        $ecrit = "trim(replace(replace(replace(coalesce(%s, ''), ?, ''), ?, ''), ?, '')) <> ''";
        $blancs = ["\n", "\r", "\t"];

        return $query->where(fn (Builder $q) => $q->whereNotNull('signe_le')
            ->orWhereRaw(sprintf($ecrit, 's'), $blancs)
            ->orWhereRaw(sprintf($ecrit, 'o'), $blancs)
            ->orWhereRaw(sprintf($ecrit, 'a'), $blancs)
            ->orWhereRaw(sprintf($ecrit, 'p'), $blancs));
    }

    /** @return BelongsTo<Employe, $this> */
    public function signataire(): BelongsTo
    {
        return $this->belongsTo(Employe::class, 'signe_par_id')->withoutGlobalScopes();
    }

    /** @return HasMany<CompteRenduVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(CompteRenduVersion::class)->latest('id');
    }

    /** @return HasMany<CompteRenduComplement, $this> */
    public function complements(): HasMany
    {
        return $this->hasMany(CompteRenduComplement::class)->oldest('id');
    }

    /** @return BelongsTo<RendezVous, $this> */
    public function rendezVous(): BelongsTo
    {
        return $this->belongsTo(RendezVous::class, 'rendez_vous_id');
    }

    /** Signataire — l'utilisateur authentifié au dernier enregistrement.
     * @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
