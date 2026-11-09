<?php

namespace App\Models;

use App\Enums\DomainePermission;
use App\Enums\StatutEnregistrement;
use App\Enums\StatutPartie;
use App\Events\EnregistrementMisAJour;
use App\Models\Concerns\AppartientAUneOrganisation;
use App\Support\TempsReel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;

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

    protected static function booted(): void
    {
        // Temps réel (28.10.2026) : l'écran suit la transcription sans relire
        static::saved(function (EnregistrementConsultation $enregistrement): void {
            if ($enregistrement->wasRecentlyCreated || $enregistrement->wasChanged(['statut', 'erreur'])) {
                self::annoncer($enregistrement->id);
            }
        });
    }

    /**
     * L'état de l'enregistrement est annoncé à sa clinique, relu après la
     * validation. Aussi après une mise à jour par requête (reprise gardée
     * contre les doubles traitements), qui ne passe pas par `saved`.
     */
    public static function annoncer(int $id): void
    {
        TempsReel::diffuser(function () use ($id): void {
            $enregistrement = self::withoutGlobalScopes()->find($id);
            if ($enregistrement !== null) {
                EnregistrementMisAJour::dispatch('clinique.'.$enregistrement->clinique_id, [
                    'id' => $enregistrement->id, 'rendez_vous_id' => $enregistrement->rendez_vous_id, 'intervention_id' => $enregistrement->intervention_id, 'sejour_id' => $enregistrement->sejour_id, 'referral_letter_id' => $enregistrement->referral_letter_id, 'statut' => $enregistrement->statut->value,
                ]);
            }
        });
    }

    protected $fillable = [
        'organisation_id', 'clinique_id', 'rendez_vous_id', 'intervention_id', 'sejour_id', 'referral_letter_id', 'user_id', 'statut', 'erreur',
        'parties_attendues', 'brouillon', 'redige_le', 'valide_le', 'redactions',
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

    /** La dictée d'un rapport de bloc (09.11.2026), à la place d'un rendez-vous */
    /** @return BelongsTo<Intervention, $this> */
    public function intervention(): BelongsTo
    {
        return $this->belongsTo(Intervention::class)->withoutGlobalScopes();
    }

    /**
     * La dictée des consignes d'une lettre de sortie (09.11.2026), à la place d'un rendez-vous
     *
     * @return BelongsTo<Sejour, $this>
     */
    public function sejour(): BelongsTo
    {
        return $this->belongsTo(Sejour::class)->withoutGlobalScopes();
    }

    /**
     * La dictée du motif et de la question d'une lettre de référence (09.11.2026)
     *
     * @return BelongsTo<ReferralLetter, $this>
     */
    public function referralLetter(): BelongsTo
    {
        return $this->belongsTo(ReferralLetter::class)->withoutGlobalScopes();
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
    /**
     * La dictée d'un rapport de bloc ne se touche qu'avec le droit Bloc, et
     * seulement depuis la clinique de son intervention : l'enregistrement est
     * borné à l'organisation, une clinique sœur aurait sinon pu l'abandonner
     * (16e vérification). Sans intervention, rien à ajouter.
     */
    public function autoriserDictee(): void
    {
        // La dictée d'une lettre de référence (09.11.2026) : droit Patients, depuis la clinique de la lettre
        if ($this->referral_letter_id !== null) {
            Gate::authorize(DomainePermission::Patients->value);
            abort_unless(ReferralLetter::query()->whereKey($this->referral_letter_id)->exists(), 404);

            return;
        }
        // La dictée d'une lettre de sortie (09.11.2026) : droit Patients, depuis la clinique du séjour
        if ($this->sejour_id !== null) {
            Gate::authorize(DomainePermission::Patients->value);
            abort_unless(Sejour::query()->whereKey($this->sejour_id)->exists(), 404);

            return;
        }
        if ($this->intervention_id === null) {
            return;
        }
        Gate::authorize(DomainePermission::Bloc->value);
        // Le scope clinique de l'intervention décide : ailleurs, 404 comme pour l'intervention
        abort_unless(Intervention::query()->whereKey($this->intervention_id)->exists(), 404);
    }

    public function dossierAudio(): string
    {
        return 'transcriptions/'.$this->id;
    }
}
