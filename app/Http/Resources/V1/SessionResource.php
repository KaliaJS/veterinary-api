<?php

namespace App\Http\Resources\V1;

use App\Auth\CalculateurPermissions;
use App\Auth\EmetteurJetons;
use App\Auth\Rattachement;
use App\Models\Employe;
use App\Models\JetonApi;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Profil de session (GET /auth/moi, et corps des émissions de jeton) :
 * l'utilisateur, l'employé de la clinique du jeton — l'auteur des
 * gestes côté front : id, nom, initiales, titre, poste —, la clinique
 * courante, les autres rattachements (changement de clinique) et les
 * permissions effectives. Un instantané informatif pour l'UI ; la
 * vérité reste la base, vérifiée à chaque requête. Session sans
 * clinique (compte sans rattachement) : `employe` et `clinique` à
 * null, permissions vides.
 */
class SessionResource extends JsonResource
{
    /** @param  list<string>|null  $permissions  Calculées en base si absentes. */
    public function __construct(
        private readonly User $user,
        private readonly ?Employe $employe,
        private readonly JetonApi $jeton,
        private readonly ?array $permissions = null,
    ) {
        parent::__construct($employe ?? $user);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'expire_le' => $this->jeton->expires_at?->toIso8601String(),
            'user' => new UtilisateurResource($this->user),
            'employe' => $this->employe ? [
                'id' => $this->employe->id,
                'nom' => $this->employe->nom,
                'initiales' => $this->employe->initiales,
                'titre' => $this->employe->titre(),
                'role_libelle' => $this->employe->role_libelle,
                'role_id' => $this->employe->role_id,
            ] : null,
            // La clinique du jeton (celle de la session, parmi les cliniques
            // de l'employé)
            'clinique' => $this->employe && $this->jeton->clinique ? [
                'id' => $this->jeton->clinique->id,
                'nom' => $this->jeton->clinique->nom,
            ] : null,
            // L'organisation de la clinique du jeton : ouverte, ou fermée
            // et pourquoi (vérification, abonnement) — page d'accès du front
            'organisation' => $this->employe ? $this->organisation($this->employe) : null,
            'cliniques' => app(EmetteurJetons::class)->rattachements($this->user)
                ->map(fn (Rattachement $rattachement): array => [
                    'id' => $rattachement->clinique->id,
                    'nom' => $rattachement->clinique->nom,
                ])
                ->values()
                ->all(),
            'permissions' => $this->permissions ?? ($this->employe ? app(CalculateurPermissions::class)->pour(
                $this->employe->loadMissing(['role', 'derogations']),
            ) : []),
        ];
    }

    /** @return array{id: int, nom: string, verification: string, verification_note: ?string, abonnement_en_cours: bool, fermeture: ?string} */
    private function organisation(Employe $employe): array
    {
        $organisation = $employe->organisation;

        return [
            'id' => $organisation->id,
            'nom' => $organisation->nom,
            'verification' => $organisation->verification->value,
            'verification_note' => $organisation->verification_note,
            'abonnement_en_cours' => $organisation->abonnementEnCours(),
            'fermeture' => $organisation->fermeture()['code'] ?? null,
        ];
    }
}
