<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\StatutOrdonnance;
use App\Enums\TypeMouvementStupefiant;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Stupefiants\EnregistrerMouvementStupefiantRequest;
use App\Http\Resources\V1\MouvementStupefiantResource;
use App\Models\MouvementStupefiant;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Models\Produit;
use App\Services\RegistreStupefiants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Registre des stupéfiants de la clinique du jeton (art. 57, 63 et 64
 * OCStup, docs/data-model.md §16) : journal immuable, création seule.
 * Une fiche par substance (`produit_id`), chaque ligne avec son type et le
 * stock après elle (mise aux normes, 01.11.2026). Le registre se lit PAR
 * CLINIQUE : c'est elle qui le tient et le présente aux contrôles.
 */
class MouvementStupefiantController extends Controller
{
    public function __construct(private RegistreStupefiants $registre) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MouvementStupefiant::class);

        $mouvements = MouvementStupefiant::query()
            ->with(['produit', 'corrige'])
            ->when($request->integer('produit_id'), fn ($requete, int $produit) => $requete->where('produit_id', $produit))
            ->when($request->integer('patient_id'), fn ($requete, int $patient) => $requete->where('patient_id', $patient))
            ->latest('created_at')
            ->latest('id')
            ->paginate($this->parPage($request));

        return MouvementStupefiantResource::collection($mouvements);
    }

    /**
     * Les ordonnances à proposer pour une remise (01.11.2026) : celles en
     * cours de l'animal qui prescrivent la substance. Le numéro, la date et
     * le vétérinaire seulement : le registre n'a pas à lire la prescription.
     */
    public function ordonnances(Request $request): JsonResponse
    {
        // Ce qu'un animal prend : le droit d'inscrire au registre (Stupéfiants), une substance du registre
        $this->authorize('create', MouvementStupefiant::class);
        $donnees = $request->validate([
            'patient_id' => ['required', 'integer'],
            'produit_id' => ['required', 'integer', Rule::exists('produits', 'id')->where('organisation_id', app(ContexteClinique::class)->organisationId())->where('est_stupefiant', true)],
        ], ['produit_id.exists' => 'Ce produit n’est pas une substance du registre.']);

        // Le vétérinaire peut être d'une clinique sœur : sa fiche se lit hors du scope de la clinique du jeton
        $ordonnances = Ordonnance::query()->with(['employe' => fn ($employe) => $employe->withoutGlobalScopes()])
            ->where('patient_id', $donnees['patient_id'])
            ->where('statut', StatutOrdonnance::Active)
            // Valable un mois (art. 47, al. 2 OCStup)
            ->whereDate('signee_le', '>=', aujourdhui()->subMonth()->toDateString())
            ->whereHas('lignes', fn ($ligne) => $ligne->where('produit_id', $donnees['produit_id']))
            ->latest('signee_le')->latest('id')->limit(20)->get();

        return response()->json(['data' => $ordonnances->map(fn (Ordonnance $o): array => [
            'id' => $o->id,
            'numero' => $o->numero,
            'signee_le' => $o->signee_le->toDateString(),
            'veterinaire' => $o->employe?->nom,
        ])->all()]);
    }

    public function store(EnregistrerMouvementStupefiantRequest $request): MouvementStupefiantResource
    {
        $this->authorize('create', MouvementStupefiant::class);

        $donnees = $request->validated();
        $type = TypeMouvementStupefiant::from($donnees['type']);
        $cliniqueId = (int) app(ContexteClinique::class)->cliniqueId();

        $mouvement = DB::transaction(function () use ($donnees, $type, $cliniqueId, $request): MouvementStupefiant {
            $produit = Produit::query()->findOrFail($donnees['produit_id']);
            $details = [
                'motif' => filled($donnees['motif'] ?? null) ? trim($donnees['motif']) : $type->libelle(),
                'patient' => $type->versUnAnimal() ? Patient::query()->with('client')->findOrFail($donnees['patient_id']) : null,
                // Une remise : l'ordonnance de l'app (tableau b) ou le numéro du formulaire officiel (tableau a), contrôlés par la requête
                'ordonnance' => filled($donnees['ordonnance_id'] ?? null) ? Ordonnance::query()->findOrFail($donnees['ordonnance_id']) : null,
                'ordonnance_officielle' => $donnees['ordonnance_officielle'] ?? null,
                'fournisseur' => $donnees['fournisseur'] ?? null,
                'bulletin' => $donnees['bulletin'] ?? null,
                'bulletin_le' => $donnees['bulletin_le'] ?? null,
            ];

            $quantite = match ($type) {
                TypeMouvementStupefiant::Correction => $this->correction($donnees, $produit, $details),
                TypeMouvementStupefiant::Inventaire => $this->inventaire($donnees, $produit, $cliniqueId, $details),
                TypeMouvementStupefiant::Visa => 0.0,
                default => $type->sens() * (float) $donnees['quantite'],
            };

            return $this->registre->ecrire($produit, $type, $quantite, $details, $cliniqueId, $request->user());
        });

        return new MouvementStupefiantResource($mouvement->load(['produit', 'corrige']));
    }

    /**
     * Une correction pointe une ligne de la même substance, de la clinique,
     * qui n'est ni un visa ni elle-même une correction.
     *
     * @param  array<string, mixed>  $donnees
     * @param  array<string, mixed>  $details
     */
    private function correction(array $donnees, Produit $produit, array &$details): float
    {
        $corrigee = MouvementStupefiant::query()->whereKey($donnees['corrige_id'])->where('produit_id', $produit->id)->first();
        if ($corrigee === null || in_array($corrigee->type, [TypeMouvementStupefiant::Visa, TypeMouvementStupefiant::Correction], true)) {
            throw ValidationException::withMessages(['corrige_id' => 'Choisissez une ligne de cette substance à corriger.']);
        }
        $details['corrige_id'] = $corrigee->id;

        return (float) $donnees['quantite'];
    }

    /**
     * L'inventaire : ce qui est compté contre ce que dit le registre ; un
     * écart doit être expliqué (OSAV D 06 : écarts justifiés et corrigés).
     *
     * @param  array<string, mixed>  $donnees
     * @param  array<string, mixed>  $details
     */
    private function inventaire(array $donnees, Produit $produit, int $cliniqueId, array &$details): float
    {
        $compte = round((float) $donnees['quantite_comptee'], 3);
        $ecart = round($compte - $this->registre->soldeVerrouille($produit, $cliniqueId), 3);
        if ($ecart !== 0.0 && blank($donnees['motif'] ?? null)) {
            throw ValidationException::withMessages(['motif' => 'Le compte diffère du registre de '.RegistreStupefiants::quantite($ecart).' : expliquez l’écart.']);
        }
        $details['quantite_comptee'] = $compte;
        if ($ecart === 0.0 && blank($donnees['motif'] ?? null)) {
            $details['motif'] = 'Stock compté, conforme au registre';
        }

        return $ecart;
    }
}
