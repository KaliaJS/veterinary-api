<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
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
 * patient est introuvable. Une pesée garde qui l'a faite (06.11.2026).
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

        // La pesée du jour refaite est celle de qui vient de peser (06.11.2026)
        $pesee = $existante === null
            ? $patient->pesees()->create(['pesee_le' => $peseeLe, 'poids_kg' => $poids])
            : tap($existante)->update(['poids_kg' => $poids, 'employe_id' => app(ContexteClinique::class)->employe()->id ?? $existante->employe_id]);

        return new PeseeResource($pesee->load('employe'));
    }

    public function update(ModifierPeseeRequest $request, Patient $patient, int $pesee): PeseeResource
    {
        $this->authorize('update', $patient);

        /** @var Pesee $enregistrement */
        $enregistrement = $patient->pesees()->findOrFail($pesee);
        $enregistrement->update($request->validated());

        return new PeseeResource($enregistrement->load('employe'));
    }

    public function destroy(Patient $patient, int $pesee): Response
    {
        $this->authorize('update', $patient);

        $patient->pesees()->findOrFail($pesee)->delete();

        return response()->noContent();
    }
}
