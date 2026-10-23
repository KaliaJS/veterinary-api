<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\CalculateurPermissions;
use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Http\Controllers\Controller;
use App\Models\DerogationPermission;
use App\Models\Employe;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Rôles et droits de l'organisation (23.10.2026, Réglages › Rôles et
 * droits, administrateurs) : chaque rôle accorde des droits (domaines et
 * droits fins, DomainePermission), chaque personne peut avoir des
 * exceptions (dérogations : un droit accordé ou retiré au-dessus de son
 * rôle). Les défauts (RolesParDefaut) ne sont qu'un point de départ.
 * Garde-fou : l'organisation garde toujours au moins un administrateur
 * actif (droit `organisation`) ; un rôle porté par quelqu'un ne se
 * supprime pas.
 */
class OrganisationRoleController extends Controller
{
    public function __construct(private CalculateurPermissions $calculateur) {}

    public function index(): JsonResponse
    {
        $roles = Role::query()->withCount('employes')->orderBy('id')->get();
        $employes = Employe::withoutGlobalScopes()
            ->where('organisation_id', $this->organisationId())
            ->with('derogations')
            ->orderBy('nom')
            ->get();

        return response()->json([
            'data' => $roles->map(fn (Role $role): array => $this->role($role))->values(),
            'droits' => collect(DomainePermission::cases())->map(fn (DomainePermission $droit): array => [
                'valeur' => $droit->value,
                'libelle' => $droit->libelle(),
                'description' => $droit->description(),
                'groupe' => $droit->groupe(),
            ])->values(),
            // Les exceptions de chacun, pour l'écran (le rôle se lit sur la fiche)
            'exceptions' => $employes
                ->filter(fn (Employe $employe): bool => $employe->derogations->isNotEmpty())
                ->map(fn (Employe $employe): array => [
                    'employe_id' => $employe->id,
                    'droits' => $employe->derogations->map(fn (DerogationPermission $d): array => [
                        'droit' => $d->domaine->value,
                        'accorde' => $d->est_accordee,
                    ])->values(),
                ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $this->valider($request);

        $role = Role::create(['nom' => $donnees['nom'], 'domaines' => $donnees['droits']]);

        return response()->json(['data' => $this->role($role->loadCount('employes'))], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $donnees = $this->valider($request, $role);

        $this->gardantUnAdministrateur(fn () => $role->update([
            ...isset($donnees['nom']) ? ['nom' => $donnees['nom']] : [],
            ...isset($donnees['droits']) ? ['domaines' => $donnees['droits']] : [],
        ]));

        return response()->json(['data' => $this->role($role->loadCount('employes'))]);
    }

    public function destroy(Role $role): Response
    {
        if ($role->employes()->withoutGlobalScopes()->exists()) {
            throw ValidationException::withMessages(['role' => 'Des personnes ont ce rôle : donnez-leur un autre rôle avant de le supprimer.']);
        }

        $role->delete();

        return response()->noContent();
    }

    /** Exceptions d'une personne : la liste complète remplace la précédente. */
    public function exceptions(Request $request, int $employe): JsonResponse
    {
        $fiche = EquipeEmployeController::employeDeLOrganisation($employe);
        $donnees = $request->validate([
            'droits' => ['present', 'array'],
            'droits.*.droit' => ['required', 'distinct', Rule::enum(DomainePermission::class)],
            'droits.*.accorde' => ['required', 'boolean'],
        ]);

        $this->gardantUnAdministrateur(function () use ($fiche, $donnees): void {
            $fiche->derogations()->delete();
            foreach ($donnees['droits'] as $ligne) {
                $fiche->derogations()->create(['domaine' => $ligne['droit'], 'est_accordee' => $ligne['accorde']]);
            }
        });

        $fiche->load('derogations');

        return response()->json(['data' => [
            'employe_id' => $fiche->id,
            'droits' => $fiche->derogations->map(fn (DerogationPermission $d): array => ['droit' => $d->domaine->value, 'accorde' => $d->est_accordee])->values(),
            'effectifs' => $this->calculateur->pour($fiche->load('role')),
        ]]);
    }

    /** @return array<string, mixed> */
    private function role(Role $role): array
    {
        return [
            'id' => $role->id,
            'nom' => $role->nom,
            'droits' => collect($role->domaines)->map(fn (DomainePermission $droit): string => $droit->value)->values(),
            'employes' => $role->employes_count ?? 0,
        ];
    }

    /** @return array{nom?: string, droits?: list<string>} */
    private function valider(Request $request, ?Role $role = null): array
    {
        $creation = $role === null;

        return $request->validate([
            'nom' => [$creation ? 'required' : 'sometimes', 'string', 'max:60',
                Rule::unique('roles', 'nom')->where('organisation_id', $this->organisationId())->ignore($role?->id)],
            'droits' => [$creation ? 'present' : 'sometimes', 'array'],
            'droits.*' => ['distinct', Rule::enum(DomainePermission::class)],
        ], [
            'nom.unique' => 'Un rôle porte déjà ce nom.',
        ]);
    }

    /**
     * Applique un changement de droits et l'annule s'il laisse
     * l'organisation sans administrateur actif.
     */
    private function gardantUnAdministrateur(callable $changement): void
    {
        DB::transaction(function () use ($changement): void {
            $changement();

            $administrateurs = Employe::withoutGlobalScopes()
                ->where('organisation_id', $this->organisationId())
                ->where('acces_suspendu', false)
                ->where(fn ($q) => $q->whereNull('fin_contrat_le')->orWhereDate('fin_contrat_le', '>=', aujourdhui()))
                ->with('role', 'derogations')
                ->get()
                ->filter(fn (Employe $employe): bool => in_array(DomainePermission::Organisation->value, $this->calculateur->pour($employe), true));

            if ($administrateurs->isEmpty()) {
                throw ValidationException::withMessages([
                    'droits' => 'L’organisation doit garder au moins un administrateur actif (droit Administration).',
                ]);
            }
        });
    }

    private function organisationId(): int
    {
        return (int) app(ContexteClinique::class)->organisationId();
    }
}
