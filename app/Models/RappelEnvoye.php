<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneClinique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un rappel envoyé au propriétaire (28.10.2026) : vaccin ou rendez-vous de
 * contrôle, par e-mail, automatique ou demandé par la clinique ; envoyé ou
 * en échec (retenté trois fois au plus). Écrit par la commande planifiée,
 * hors contexte de clinique : la clinique est posée explicitement.
 */
class RappelEnvoye extends Model
{
    use AppartientAUneClinique;

    protected $table = 'rappels_envoyes';

    protected $fillable = [
        'clinique_id', 'patient_id', 'client_id', 'objet', 'vaccination_id', 'rendez_vous_id', 'libelle',
        'echeance_le', 'canal', 'adresse', 'statut', 'erreur', 'tentatives', 'manuel', 'user_id', 'envoye_le', 'cle',
    ];

    protected function casts(): array
    {
        return [
            'echeance_le' => 'date',
            'envoye_le' => 'datetime',
            'manuel' => 'boolean',
            'tentatives' => 'integer',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
