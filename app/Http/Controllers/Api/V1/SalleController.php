<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Salles\EnregistrerSalleRequest;
use App\Http\Requests\V1\Salles\ModifierSalleRequest;
use App\Http\Resources\V1\SalleResource;
use App\Models\Salle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Premier endpoint métier — il porte les conventions de l'API :
 * ressources JSON, FormRequests, pagination, et surtout AUCUN filtre
 * clinique ici — le scope multi-clinique est global (middleware
 * `clinique` + trait AppartientAUneClinique).
 */
class SalleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return SalleResource::collection(
            Salle::query()->orderBy('nom')->paginate($this->parPage($request)),
        );
    }

    public function store(EnregistrerSalleRequest $request): SalleResource
    {
        return new SalleResource(Salle::create($request->validated()));
    }

    public function show(Salle $salle): SalleResource
    {
        return new SalleResource($salle);
    }

    public function update(ModifierSalleRequest $request, Salle $salle): SalleResource
    {
        $salle->update($request->validated());

        return new SalleResource($salle);
    }
}
