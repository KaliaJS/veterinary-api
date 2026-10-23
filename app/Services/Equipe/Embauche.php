<?php

namespace App\Services\Equipe;

use App\Auth\RolesParDefaut;
use App\Models\Employe;
use App\Models\Organisation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Embauche d'un compte dans une organisation : la fiche RH, son rôle, ses
 * cliniques. Deux chemins y mènent, le code employé donné par la personne
 * et l'invitation par e-mail qu'elle accepte ; tous deux exigent un
 * compte (chaque personne a le sien).
 */
class Embauche
{
    /**
     * Le rôle que recevra la personne : celui choisi, sinon celui du poste
     * (ASV, sinon Vétérinaire). Les contrôleurs le vérifient avant
     * d'embaucher ou d'inviter (personne ne donne plus de droits qu'il
     * n'en a).
     *
     * @param  array<string, mixed>  $donnees
     */
    public function role(Organisation $organisation, array $donnees): ?Role
    {
        return Role::withoutGlobalScope('organisation')
            ->where('organisation_id', $organisation->id)
            ->when(
                isset($donnees['role_id']),
                fn ($q) => $q->whereKey($donnees['role_id']),
                fn ($q) => $q->where('nom', Str::contains((string) ($donnees['poste'] ?? ''), 'ASV') ? RolesParDefaut::ASV : RolesParDefaut::VETERINAIRE),
            )
            ->first();
    }

    /**
     * @param  array<string, mixed>  $donnees  poste, cliniques ou toutes_les_cliniques, et la fiche (rôle, taux, contrat…)
     * @param  string  $champ  champ de l'erreur « déjà dans l'équipe » (code ou email)
     */
    public function embaucher(Organisation $organisation, User $compte, array $donnees, string $champ = 'code'): Employe
    {
        if (Employe::withoutGlobalScopes()->where('organisation_id', $organisation->id)->where('user_id', $compte->id)->exists()) {
            throw ValidationException::withMessages([
                $champ => 'Cette personne fait déjà partie de l’équipe : ajoutez-lui des cliniques depuis sa fiche.',
            ]);
        }

        $this->verifierPlace($organisation);

        $siennes = $organisation->cliniques()->pluck('cliniques.id')->all();
        $cliniques = ($donnees['toutes_les_cliniques'] ?? false)
            ? $siennes
            : array_values(array_intersect(array_map('intval', $donnees['cliniques'] ?? []), $siennes));
        if ($cliniques === []) {
            throw ValidationException::withMessages(['cliniques' => 'Choisissez au moins une clinique.']);
        }

        $roleId = $this->role($organisation, $donnees)?->id;

        $nom = $donnees['nom'] ?? $compte->name;

        return DB::transaction(function () use ($donnees, $organisation, $compte, $cliniques, $roleId, $nom): Employe {
            $employe = Employe::create([
                'organisation_id' => $organisation->id,
                'user_id' => $compte->id,
                'nom' => $nom,
                'initiales' => self::initialesDe($nom),
                'role_libelle' => $donnees['poste'],
                'role_id' => $roleId,
                'taux' => $donnees['taux'] ?? 100,
                'contrat' => $donnees['contrat'] ?? 'CDI',
                'contrat_statut' => $donnees['contrat_statut'],
                'salaire_mensuel' => $donnees['salaire_mensuel'] ?? null,
                'entree_le' => $donnees['entree_le'] ?? today(),
                'tel' => $donnees['tel'] ?? null,
                'email' => $donnees['email'] ?? $compte->email,
                'conges_droit' => $donnees['conges_droit'] ?? 25,
                'conges_pris' => 0,
            ]);
            $employe->cliniques()->attach($cliniques);

            return $employe;
        });
    }

    /** La formule de l'organisation a-t-elle encore une place d'employé actif ? */
    public function verifierPlace(Organisation $organisation): void
    {
        $limite = $organisation->plan->limites()['employes'];
        if ($limite !== null && $organisation->employesActifs() + 1 > $limite) {
            throw ValidationException::withMessages([
                'employes' => "Limite de {$limite} employés actifs atteinte : passez à une formule supérieure.",
            ]);
        }
    }

    public static function initialesDe(string $nom): string
    {
        return Str::upper(
            collect(preg_split('/[\s.]+/', str_replace('Dr ', '', $nom)))
                ->filter()
                ->take(2)
                ->map(fn (string $mot): string => Str::substr($mot, 0, 1))
                ->implode(''),
        );
    }
}
