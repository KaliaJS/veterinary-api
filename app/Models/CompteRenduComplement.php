<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Complément d'un compte rendu SOAP signé (23.10.2026) : daté, signé par
 * son auteur, jamais modifié ni supprimé.
 */
class CompteRenduComplement extends Model
{
    protected $table = 'comptes_rendus_complements';

    protected $fillable = ['compte_rendu_id', 'texte', 'signe_par_id'];

    /** @return BelongsTo<Employe, $this> */
    public function signataire(): BelongsTo
    {
        return $this->belongsTo(Employe::class, 'signe_par_id')->withoutGlobalScopes();
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Un complément de compte rendu ne se modifie pas : ajoutez-en un autre.'));
        static::deleting(fn (): never => throw new LogicException('Un complément de compte rendu ne se supprime pas.'));
    }
}
