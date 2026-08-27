<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Hospitalisation\EnregistrerConstanteRequest;
use App\Http\Resources\V1\SejourConstanteResource;
use App\Models\Sejour;
use Illuminate\Validation\ValidationException;

/**
 * Relevés de constantes d'un séjour (T°, FC, FR) — enfants du dossier,
 * résolus via le séjour.
 */
class ConstanteSejourController extends Controller
{
    public function store(EnregistrerConstanteRequest $request, Sejour $sejour): SejourConstanteResource
    {
        $this->authorize('update', $sejour);

        if ($sejour->estSorti()) {
            throw ValidationException::withMessages(['sejour' => 'Un séjour sorti ne se modifie plus.']);
        }

        return new SejourConstanteResource($sejour->constantes()->create($request->validated()));
    }
}
