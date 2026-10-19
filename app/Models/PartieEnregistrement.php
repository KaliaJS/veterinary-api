<?php

namespace App\Models;

use App\Enums\StatutPartie;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une partie d'enregistrement : un fichier audio envoyé par morceaux,
 * assemblé, transcrit puis effacé. Les segments (voix, début, fin, texte)
 * sont chiffrés en base. La durée transcrite est celle qu'on facture.
 * Jamais résolue directement : toujours par son enregistrement.
 */
class PartieEnregistrement extends Model
{
    protected $table = 'parties_enregistrement';

    protected $fillable = [
        'enregistrement_consultation_id', 'numero', 'statut', 'format', 'morceaux', 'fichier',
        'duree_secondes', 'segments', 'requete_fournisseur', 'tentatives', 'recue_le', 'transcrite_le',
    ];

    protected $attributes = [
        'statut' => 'envoi',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutPartie::class,
            'segments' => 'encrypted:array',
            'duree_secondes' => 'integer',
            'recue_le' => 'datetime',
            'transcrite_le' => 'datetime',
        ];
    }

    /** @return BelongsTo<EnregistrementConsultation, $this> */
    public function enregistrement(): BelongsTo
    {
        return $this->belongsTo(EnregistrementConsultation::class, 'enregistrement_consultation_id')
            ->withoutGlobalScope('organisation');
    }

    /** Dossier des morceaux reçus, en attendant l'assemblage. */
    public function dossierMorceaux(): string
    {
        return 'transcriptions/'.$this->enregistrement_consultation_id.'/'.$this->numero;
    }
}
