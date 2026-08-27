<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Stupefiants\EnregistrerMouvementStupefiantRequest;
use App\Http\Resources\V1\MouvementStupefiantResource;
use App\Models\MouvementStupefiant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Registre des stupéfiants de la clinique du jeton (art. 62 OCStup,
 * docs/data-model.md §16). Journal immuable : création seule — ni
 * update ni delete, une erreur se corrige par un mouvement inverse.
 * Contrairement au journal de stock (lecture transversale), le
 * registre se lit PAR CLINIQUE : c'est elle qui le tient et le
 * présente aux contrôles cantonaux.
 */
class MouvementStupefiantController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MouvementStupefiant::class);

        $mouvements = MouvementStupefiant::query()
            ->with(['produit', 'patient', 'employe'])
            ->when($request->integer('produit_id'), fn ($requete, int $produit) => $requete->where('produit_id', $produit))
            ->when($request->integer('patient_id'), fn ($requete, int $patient) => $requete->where('patient_id', $patient))
            ->latest('created_at')
            ->latest('id')
            ->paginate($this->parPage($request));

        return MouvementStupefiantResource::collection($mouvements);
    }

    public function store(EnregistrerMouvementStupefiantRequest $request): MouvementStupefiantResource
    {
        $this->authorize('create', MouvementStupefiant::class);

        $donnees = $request->validated();
        $quantite = (float) $donnees['quantite'];

        // Parité front (tools.js) : pas de patient sur une réception,
        // motif par défaut selon le sens du mouvement.
        $mouvement = MouvementStupefiant::create([
            'produit_id' => $donnees['produit_id'],
            'patient_id' => $quantite < 0 ? $donnees['patient_id'] : null,
            'employe_id' => $donnees['employe_id'] ?? app(ContexteClinique::class)->employe()?->id,
            'quantite' => $donnees['quantite'],
            'motif' => blank($donnees['motif'] ?? null)
                ? ($quantite < 0 ? 'Administration' : 'Réception grossiste')
                : $donnees['motif'],
            'created_at' => now(),
        ]);

        return new MouvementStupefiantResource($mouvement->load(['produit', 'patient', 'employe']));
    }
}
