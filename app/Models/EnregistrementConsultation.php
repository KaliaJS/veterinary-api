<?php

namespace App\Models;

use App\Enums\StatutEnregistrement;
use App\Enums\StatutPartie;
use App\Models\Concerns\AppartientAUneOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Enregistrement d'une consultation (19.10.2026) : une ou plusieurs parties
 * audio d'un rendez-vous, transcrites puis résumées en un brouillon de
 * compte rendu SOAP que le vétérinaire relit et valide (le compte rendu
 * lui-même reste dans `comptes_rendus`). Niveau organisation, comme le
 * dossier médical. Le brouillon est chiffré en base et n'apparaît dans
 * aucun journal.
 */
class EnregistrementConsultation extends Model
{
    use AppartientAUneOrganisation;

    protected $table = 'enregistrements_consultation';

    protected $fillable = [
        'organisation_id', 'clinique_id', 'rendez_vous_id', 'user_id', 'statut', 'erreur',
        'parties_attendues', 'brouillon', 'redige_le', 'valide_le',
    ];

    protected $attributes = [
        'statut' => 'enregistrement',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutEnregistrement::class,
            'brouillon' => 'encrypted:array',
            'redige_le' => 'datetime',
            'valide_le' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return BelongsTo<RendezVous, $this> */
    public function rendezVous(): BelongsTo
    {
        return $this->belongsTo(RendezVous::class, 'rendez_vous_id')->withoutGlobalScope('clinique');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PartieEnregistrement, $this> */
    public function parties(): HasMany
    {
        return $this->hasMany(PartieEnregistrement::class)->orderBy('numero');
    }

    /** Toutes les parties annoncées sont transcrites : le brouillon peut s'écrire. */
    public function partiesTranscrites(): bool
    {
        $attendues = (int) $this->parties_attendues;

        return $attendues > 0
            && $this->parties()->where('statut', StatutPartie::Transcrite)->count() >= $attendues;
    }

    /** Numéro de la prochaine partie (après une reprise d'enregistrement). */
    public function prochainePartie(): int
    {
        return (int) $this->parties()->max('numero') + 1;
    }

    /** Dossier des fichiers audio de l'enregistrement, sur le disque privé. */
    public function dossierAudio(): string
    {
        return 'transcriptions/'.$this->id;
    }
}
