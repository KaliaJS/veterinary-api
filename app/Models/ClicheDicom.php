<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cliché DICOM d'un examen d'imagerie — métadonnées du flux poussé
 * par la modalité (identifiants DICOM, chemin du fichier). Enfant sans
 * scope propre, résolu via l'examen. Idempotent par SOP Instance UID.
 *
 * Radios annotées (28.10.2026) : l'original envoyé est gardé tel quel
 * (empreinte SHA-256), avec l'espèce du jour, la région et l'incidence en
 * listes fixes validées par un humain, et ses annotations à part.
 */
class ClicheDicom extends Model
{
    protected $table = 'cliches_dicom';

    protected $fillable = [
        'examen_imagerie_id', 'sop_instance_uid', 'study_instance_uid',
        'series_instance_uid', 'nom_fichier', 'chemin', 'metadonnees',
        'taille_octets', 'empreinte_sha256', 'syntaxe_transfert', 'modalite_dicom',
        'lignes', 'colonnes', 'images', 'espece', 'race', 'poids_kg',
        'region', 'incidence', 'reperes_dicom', 'reperes_valides_par', 'reperes_valides_le', 'envoye_par',
    ];

    protected function casts(): array
    {
        return [
            'metadonnees' => 'array',
            'reperes_dicom' => 'array',
            'reperes_valides_le' => 'datetime',
            'taille_octets' => 'integer',
            'lignes' => 'integer',
            'colonnes' => 'integer',
            'images' => 'integer',
            'poids_kg' => 'float',
        ];
    }

    /** @return HasMany<AnnotationImagerie, $this> */
    public function annotations(): HasMany
    {
        return $this->hasMany(AnnotationImagerie::class, 'cliche_dicom_id');
    }

    /**
     * Les clichés des organisations qui ont donné leur accord pour
     * l'entraînement (28.10.2026) : sans accord, aucune image n'en sort.
     *
     * @param  Builder<ClicheDicom>  $requete
     */
    public function scopeEntrainementPermis(Builder $requete): void
    {
        $requete->whereNotNull('chemin')->whereHas('examen', fn (Builder $examen) => $examen->withoutGlobalScopes()
            ->whereHas('clinique', fn (Builder $clinique) => $clinique->withoutGlobalScopes()
                ->whereHas('societe.organisation', fn (Builder $organisation) => $organisation->whereNotNull('entrainement_consenti_le'))));
    }

    /** @return BelongsTo<ExamenImagerie, $this> */
    public function examen(): BelongsTo
    {
        return $this->belongsTo(ExamenImagerie::class, 'examen_imagerie_id');
    }
}
