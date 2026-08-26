<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\DossierMedical\EnregistrerVaccinationRequest;
use App\Http\Resources\V1\VaccinationResource;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Carnet de vaccination. Une injection du jour ÉCRASE la ligne du même
 * vaccin (même marque ou mêmes maladies — règle du carnet du front) ;
 * le signataire est l'employé du jeton.
 */
class VaccinationController extends Controller
{
    public function index(Request $request, Patient $patient): AnonymousResourceCollection
    {
        $this->authorize('view', $patient);

        return VaccinationResource::collection(
            $patient->vaccinations()
                ->with(['employe' => fn ($requete) => $requete->withoutGlobalScope('clinique')])
                ->orderBy('echeance_le')
                ->paginate($this->parPage($request)),
        );
    }

    public function store(EnregistrerVaccinationRequest $request, Patient $patient): VaccinationResource
    {
        $this->authorize('update', $patient);

        $donnees = $request->validated();

        $existante = $patient->vaccinations()
            ->where(fn (Builder $clause) => $clause
                ->where('nom', $donnees['nom'])
                ->when($donnees['marque'] ?? null, fn (Builder $requete, string $marque) => $requete->orWhere('marque', $marque)))
            ->first();

        $attributs = [...$donnees, 'employe_id' => app(ContexteClinique::class)->employe()?->id];

        $vaccination = $existante === null
            ? $patient->vaccinations()->create($attributs)
            : tap($existante)->update($attributs);

        return new VaccinationResource($vaccination);
    }
}
