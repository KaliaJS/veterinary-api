<?php

namespace App\Http\Resources\V1;

use App\Auth\CalculateurPermissions;
use App\Auth\EmetteurJetons;
use App\Auth\Rattachement;
use App\Enums\ServiceClinique;
use App\Models\CliniqueService;
use App\Models\Employe;
use App\Models\JetonApi;
use App\Models\User;
use App\Services\Plateforme\FonctionsEssai;
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
            // Cette connexion vient d'annuler la suppression demandée du compte
            'suppression_annulee' => $this->jeton->suppressionAnnulee,
            'user' => new UtilisateurResource($this->user),
            'employe' => $this->employe ? [
                'id' => $this->employe->id,
                'nom' => $this->employe->nom,
                'initiales' => $this->employe->initiales,
                'titre' => $this->employe->titre(),
                'role_libelle' => $this->employe->role_libelle,
                'role_id' => $this->employe->role_id,
                // Paraît sur le site public, s'il l'accepte (nLPD)
                'accord_site' => $this->employe->accord_site_le !== null,
            ] : null,
            // La clinique du jeton (celle de la session, parmi les cliniques
            // de l'employé)
            'clinique' => $this->employe && $this->jeton->clinique ? [
                'id' => $this->jeton->clinique->id,
                'nom' => $this->jeton->clinique->nom,
                'nombre_boxes' => $this->jeton->clinique->nombre_boxes,
                // En-tête des pièces qu'elle émet (PDF)
                'en_tete' => $this->jeton->clinique->enTeteDocument(),
                // Ses services ouvrent les modules (menus) de l'espace vétérinaire
                'services' => $this->jeton->clinique->services
                    ->sortBy(fn (CliniqueService $ligne): int => array_search($ligne->service, ServiceClinique::cases(), true))
                    ->map(fn (CliniqueService $ligne): string => $ligne->service->value)
                    ->values()->all(),
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
            // Fonctions en essai actives pour la personne, l'organisation de
            // la clinique du jeton ou tout le monde (27.10.2026) : l'app cache
            // le reste, l'API le refuse (`fonction:nom`)
            'fonctions' => app(FonctionsEssai::class)->actives(
                $this->user,
                $this->employe ? app(FonctionsEssai::class)->organisationDuJeton($this->jeton) : null,
            ),
        ];
    }

    /** @return array{id: int, nom: string, verification: string, verification_note: ?string, abonnement_en_cours: bool, fermeture: ?string, identite_avant_le: ?string, inscrite_par_moi: bool} */
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
            // Ouverte en attendant l'identité de qui l'a inscrite (23.10.2026)
            'identite_avant_le' => $organisation->identite_avant_le?->toDateString(),
            'inscrite_par_moi' => $organisation->inscrite_par === $employe->user_id,
        ];
    }
}
