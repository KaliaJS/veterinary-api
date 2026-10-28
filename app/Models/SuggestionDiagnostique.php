<?php

namespace App\Models;

use App\Enums\StatutSuggestionDiagnostique;
use App\Models\Concerns\AppartientAUneOrganisation;
use App\Support\TempsReel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Suggestion diagnostique d'un rendez-vous (23.10.2026) : ce que Claude
 * propose à partir du compte rendu en cours, de la transcription et du
 * signalement (hypothèses classées, examens, signaux d'alerte). Niveau
 * organisation, comme le dossier médical. Le compte rendu envoyé et la
 * suggestion sont chiffrés en base et n'apparaissent dans aucun journal.
 * Facturée quand elle est rendue, sur la facture de renouvellement de
 * l'abonnement (`organisation_facture_id`).
 */
class SuggestionDiagnostique extends Model
{
    use AppartientAUneOrganisation;

    protected $table = 'suggestions_diagnostiques';

    protected static function booted(): void
    {
        // Alertes en temps réel (28.10.2026) : la consommation du diagnostic a changé
        static::saved(function (SuggestionDiagnostique $suggestion): void {
            if ($suggestion->wasRecentlyCreated || $suggestion->wasChanged('montant_centimes')) {
                TempsReel::alertesDeLOrganisation((int) $suggestion->organisation_id);
            }
        });
    }

    protected $fillable = [
        'organisation_id', 'clinique_id', 'rendez_vous_id', 'user_id', 'statut', 'soap', 'avec_transcription',
        'contenu', 'modele', 'jetons_entree', 'jetons_sortie', 'montant_centimes', 'organisation_facture_id', 'rendue_le',
    ];

    protected $attributes = [
        'statut' => 'en_cours',
        'avec_transcription' => false,
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutSuggestionDiagnostique::class,
            'soap' => 'encrypted:array',
            'avec_transcription' => 'boolean',
            'contenu' => 'encrypted:array',
            'jetons_entree' => 'integer',
            'jetons_sortie' => 'integer',
            'montant_centimes' => 'integer',
            'rendue_le' => 'datetime',
        ];
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
}
