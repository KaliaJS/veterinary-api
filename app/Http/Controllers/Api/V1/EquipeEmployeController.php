<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Auth\RolesParDefaut;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Equipe\EmbaucherEmployeRequest;
use App\Http\Requests\V1\Equipe\ModifierEmployeRequest;
use App\Http\Resources\V1\EmployeResource;
use App\Models\Employe;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Fiches RH : une par personne dans l'organisation, avec les cliniques
 * où elle travaille. La LISTE est celle de la clinique du jeton (écran
 * Équipe) ; les GESTES (fiche, modification, embauche) sont bornés à
 * l'ORGANISATION — c'est l'écran Organisation qui les pilote, l'employé
 * visé peut ne pas travailler dans la clinique du jeton (résolution
 * sans binding implicite, comme le compte rendu SOAP). L'embauche lie le
 * compte Veterinary de la personne (son code employé) et respecte la
 * limite d'employés ACTIFS de la formule SaaS. Jamais de suppression
 * d'employé : le licenciement est un état.
 */
class EquipeEmployeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Employe::class);

        $employes = Employe::query()
            ->with(['cliniques', 'lignePlanning', 'congesAVenir'])
            ->orderBy('id')
            ->paginate($this->parPage($request));

        return EmployeResource::collection($employes);
    }

    public function show(Request $request, int $employe): EmployeResource
    {
        $fiche = $this->employeDeLOrganisation($employe);
        $this->authorize('view', $fiche);

        return new EmployeResource($fiche->load(['cliniques', 'lignePlanning']));
    }

    public function store(EmbaucherEmployeRequest $request): JsonResponse
    {
        $this->authorize('create', Employe::class);

        $donnees = $request->validated();
        $organisation = app(ContexteClinique::class)->clinique()->societe->organisation;
        $compte = CompteEmployeController::compteDuCode($donnees['code']);

        if (Employe::withoutGlobalScopes()->where('organisation_id', $organisation->id)->where('user_id', $compte->id)->exists()) {
            throw ValidationException::withMessages([
                'code' => 'Cette personne fait déjà partie de l’équipe : ajoutez-lui des cliniques depuis sa fiche.',
            ]);
        }

        $limite = $organisation->plan->limites()['employes'];
        if ($limite !== null && $organisation->employesActifs() + 1 > $limite) {
            throw ValidationException::withMessages([
                'employes' => "Limite de {$limite} employés actifs atteinte — formule supérieure requise.",
            ]);
        }

        $cliniques = ($donnees['toutes_les_cliniques'] ?? false)
            ? $organisation->cliniques()->pluck('cliniques.id')->all()
            : array_map('intval', $donnees['cliniques']);

        $roleId = $donnees['role_id']
            ?? Role::withoutGlobalScope('organisation')
                ->where('organisation_id', $organisation->id)
                ->where('nom', Str::contains($donnees['poste'], 'ASV') ? RolesParDefaut::ASV : RolesParDefaut::VETERINAIRE)
                ->value('id');

        $nom = $donnees['nom'] ?? $compte->name;

        $embauche = DB::transaction(function () use ($donnees, $organisation, $compte, $cliniques, $roleId, $nom): Employe {
            $employe = Employe::create([
                'organisation_id' => $organisation->id,
                'user_id' => $compte->id,
                'nom' => $nom,
                'initiales' => $this->initialesDe($nom),
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

        return (new EmployeResource($embauche->load('cliniques')))->response()->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function update(ModifierEmployeRequest $request, int $employe): EmployeResource
    {
        $fiche = $this->employeDeLOrganisation($employe);
        $this->authorize('update', $fiche);

        $fiche->update($request->validated());

        return new EmployeResource($fiche->load('cliniques'));
    }

    /**
     * Résolution SANS binding implicite (qui bornerait aux employés de la
     * clinique du jeton) : un employé de l'organisation, où qu'il
     * travaille.
     */
    public static function employeDeLOrganisation(int $id): Employe
    {
        return Employe::withoutGlobalScope('clinique')->findOrFail($id);
    }

    private function initialesDe(string $nom): string
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
