<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Auth\RolesParDefaut;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Equipe\EmbaucherEmployeRequest;
use App\Http\Requests\V1\Equipe\ModifierEmployeRequest;
use App\Http\Resources\V1\EmployeResource;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Fiches RH. La LISTE est celle de la clinique du jeton (écran
 * Équipe) ; les GESTES (fiche, modification, embauche multi-cliniques)
 * sont bornés à l'ORGANISATION — c'est l'écran Organisation qui les
 * pilote, l'employé visé peut être d'une clinique sœur (résolution sans
 * binding implicite, comme le compte rendu SOAP). L'embauche respecte
 * la limite d'employés ACTIFS de la formule SaaS. Jamais de suppression
 * d'employé : le licenciement est un état.
 */
class EquipeEmployeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Employe::class);

        $employes = Employe::query()
            ->with('lignePlanning')
            ->orderBy('id')
            ->paginate($this->parPage($request));

        return EmployeResource::collection($employes);
    }

    public function show(Request $request, int $employe): EmployeResource
    {
        $fiche = $this->employeDeLOrganisation($employe);

        $this->authorize('view', $fiche);

        return new EmployeResource($fiche->load('lignePlanning'));
    }

    public function store(EmbaucherEmployeRequest $request): JsonResponse
    {
        $this->authorize('create', Employe::class);

        $donnees = $request->validated();
        $contexte = app(ContexteClinique::class);
        $organisation = $contexte->clinique()->societe->organisation;

        $cibles = ($donnees['toutes_les_cliniques'] ?? false)
            ? $organisation->cliniques()->pluck('cliniques.id')->all()
            : [(int) ($donnees['clinique_id'] ?? $contexte->cliniqueId())];

        $limite = $organisation->plan->limites()['employes'];

        if ($limite !== null && $organisation->employesActifs() + count($cibles) > $limite) {
            throw ValidationException::withMessages([
                'employes' => "Limite de {$limite} employés actifs atteinte — formule supérieure requise.",
            ]);
        }

        $roleId = $donnees['role_id']
            ?? Role::withoutGlobalScope('organisation')
                ->where('organisation_id', $organisation->id)
                ->where('nom', Str::contains($donnees['poste'], 'ASV') ? RolesParDefaut::ASV : RolesParDefaut::VETERINAIRE)
                ->value('id');

        $embauches = DB::transaction(fn () => collect($cibles)->map(
            // Seule dérogation à l'invariant d'écriture locale : le
            // geste RH cible des cliniques sœurs, validées dans
            // l'organisation — les événements du trait sont contournés,
            // la clinique est posée explicitement.
            fn (int $cliniqueId): Employe => Employe::withoutEvents(fn (): Employe => Employe::create([
                'clinique_id' => $cliniqueId,
                'nom' => $donnees['nom'],
                'initiales' => $this->initialesDe($donnees['nom']),
                'role_libelle' => $donnees['poste'],
                'role_id' => $roleId,
                'taux' => $donnees['taux'] ?? 100,
                'contrat' => $donnees['contrat'] ?? 'CDI',
                'contrat_statut' => $donnees['contrat_statut'],
                'salaire_mensuel' => $donnees['salaire_mensuel'] ?? null,
                'entree_le' => $donnees['entree_le'] ?? today(),
                'tel' => $donnees['tel'] ?? null,
                'email' => $donnees['email'] ?? null,
                'conges_droit' => $donnees['conges_droit'] ?? 25,
                'conges_pris' => 0,
            ])),
        ));

        return EmployeResource::collection($embauches)->response()->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function update(ModifierEmployeRequest $request, int $employe): EmployeResource
    {
        $fiche = $this->employeDeLOrganisation($employe);

        $this->authorize('update', $fiche);

        $fiche->update($request->validated());

        return new EmployeResource($fiche);
    }

    /**
     * Résolution SANS binding implicite (qui bornerait à la clinique du
     * jeton) : l'employé de n'importe quelle clinique de l'organisation.
     */
    public static function employeDeLOrganisation(int $id): Employe
    {
        return Employe::query()
            ->withoutGlobalScope('clinique')
            ->whereIn('clinique_id', Clinique::query()
                ->whereRelation('societe', 'organisation_id', app(ContexteClinique::class)->organisationId())
                ->pluck('id'))
            ->findOrFail($id);
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
