<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneClinique;
use Database\Factories\SessionTeleconsultationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Session de téléconsultation — PAR CLINIQUE, dossier maître de
 * l'appel vidéo (docs/branchement.md §1.8). Deux entrées : depuis un
 * RDV `tele` du planning (qui n'est que sa projection — même patron
 * que le bloc) ou un appel libre vers un patient. La clôture calcule
 * la durée côté serveur et, sur un RDV lié, le passe en `fini` avec
 * facture. Jamais de suppression.
 */
class SessionTeleconsultation extends Model
{
    /** @use HasFactory<SessionTeleconsultationFactory> */
    use AppartientAUneClinique, HasFactory;

    /** Miroir des défauts de la base, connus dès la création (sans relire la ligne) */
    protected $attributes = [
        'cloture_rendez_vous' => false,
    ];

    protected $table = 'sessions_teleconsultation';

    protected $fillable = [
        'clinique_id', 'rendez_vous_id', 'patient_id', 'motif',
        'demarre_le', 'demarre_par_id', 'terminee_le', 'duree_secondes',
        'cloture_rendez_vous',
    ];

    protected function casts(): array
    {
        return [
            'demarre_le' => 'datetime',
            'terminee_le' => 'datetime',
            'duree_secondes' => 'integer',
            'cloture_rendez_vous' => 'boolean',
        ];
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }

    /** Projection planning de la session (RDV type `tele`).
     * @return BelongsTo<RendezVous, $this> */
    public function rendezVous(): BelongsTo
    {
        return $this->belongsTo(RendezVous::class, 'rendez_vous_id');
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<Employe, $this> */
    public function demarrePar(): BelongsTo
    {
        return $this->belongsTo(Employe::class, 'demarre_par_id');
    }

    public function estTerminee(): bool
    {
        return $this->terminee_le !== null;
    }
}
