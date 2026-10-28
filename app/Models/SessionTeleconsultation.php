<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneClinique;
use Database\Factories\SessionTeleconsultationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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
        'cloture_rendez_vous', 'lien_envoye_le',
    ];

    protected $hidden = ['jeton_client', 'jeton_client_chiffre'];

    /** Le lien du client ne sert plus passé ce délai, même sans « Terminer » (onglet fermé) */
    public const int HEURES_LIEN = 12;

    protected function casts(): array
    {
        return [
            'demarre_le' => 'datetime',
            'terminee_le' => 'datetime',
            'duree_secondes' => 'integer',
            'cloture_rendez_vous' => 'boolean',
            'lien_envoye_le' => 'datetime',
            'jeton_client_chiffre' => 'encrypted',
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

    /** L'appel accepte encore la mise en relation (ni terminé, ni abandonné depuis des heures). */
    public function estJoignable(): bool
    {
        return ! $this->estTerminee() && $this->demarre_le->greaterThan(now()->subHours(self::HEURES_LIEN));
    }

    /**
     * Le lien secret du client (28.10.2026) : créé une fois, le même à
     * chaque envoi ; seule son empreinte sert à retrouver la session.
     */
    public function jetonClient(): string
    {
        if ($this->jeton_client_chiffre) {
            return $this->jeton_client_chiffre;
        }
        $clair = Str::random(48);
        $this->forceFill(['jeton_client' => self::empreinte($clair), 'jeton_client_chiffre' => $clair])->save();

        return $clair;
    }

    public function lienClient(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/visio/'.$this->jetonClient();
    }

    public static function empreinte(string $clair): string
    {
        return hash('sha256', $clair);
    }

    /** La session d'un lien, hors de toute clinique (le client n'a pas de compte). */
    public static function parJeton(string $clair): self
    {
        return self::withoutGlobalScopes()->where('jeton_client', self::empreinte($clair))->firstOrFail();
    }
}
