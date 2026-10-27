<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Jeton Sanctum lié à une clinique : le middleware `clinique` en
 * dérive le contexte appliqué globalement (scopes multi-clinique).
 *
 * @property-read User $tokenable  les jetons de l'application sont ceux d'un compte
 * @property int|null $clinique_id
 * @property Carbon|null $session_depuis
 * @property Carbon|null $created_at
 */
class JetonApi extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    /**
     * Jeton qui vient d'annuler une demande de suppression du compte (la
     * connexion qui l'émet) : la réponse de connexion le dit une fois.
     * Pas une colonne, seulement sur le jeton tout juste émis.
     */
    public bool $suppressionAnnulee = false;

    protected function casts(): array
    {
        return [...parent::casts(), 'session_depuis' => 'datetime'];
    }

    /** Début de la session (la connexion d'origine), gardé à titre d'information : une connexion n'a plus de limite totale (27.10.2026). */
    public function debutSession(): Carbon
    {
        return $this->session_depuis ?? $this->created_at ?? now();
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
    }
}
