<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\DossierMedical\EnregistrerPeseeRequest;
use App\Http\Requests\V1\DossierMedical\ModifierPeseeRequest;
use App\Http\Resources\V1\PeseeResource;
use App\Models\Patient;
use App\Models\Pesee;
use Illuminate\Http\Response;

/**
 * Historique de poids daté — ajout du jour (remplace la pesée du même
 * jour), correction et suppression (pesée erronée : suppression
 * tolérée, docs/data-model.md « Décisions ouvertes »). Les enfants du
 * dossier se résolvent toujours VIA le patient : une pesée d'un autre
 * patient est introuvable.
 */
class PeseeController extends Controller
{
    public function store(EnregistrerPeseeRequest $request, Patient $patient): PeseeResource
    {
        $this->authorize('update', $patient);

        $peseeLe = $request->validated('pesee_le') ?? aujourdhui()->toDateString();
        $poids = $request->validated('poids_kg');

        /** @var Pesee|null $existante */
        $existante = $patient->pesees()->whereDate('pesee_le', $peseeLe)->first();

        $pesee = $existante === null
            ? $patient->pesees()->create(['pesee_le' => $peseeLe, 'poids_kg' => $poids])
            : tap($existante)->update(['poids_kg' => $poids]);

        return new PeseeResource($pesee);
    }

    public function update(ModifierPeseeRequest $request, Patient $patient, int $pesee): PeseeResource
    {
        $this->authorize('update', $patient);

        /** @var Pesee $enregistrement */
        $enregistrement = $patient->pesees()->findOrFail($pesee);
        $enregistrement->update($request->validated());

        return new PeseeResource($enregistrement);
    }

    public function destroy(Patient $patient, int $pesee): Response
    {
        $this->authorize('update', $patient);

        $patient->pesees()->findOrFail($pesee)->delete();

        return response()->noContent();
    }
}
