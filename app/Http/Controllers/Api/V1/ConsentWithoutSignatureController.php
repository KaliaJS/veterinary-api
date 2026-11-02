<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\ConsentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\InterventionResource;
use App\Models\Intervention;
use App\Services\Bloc\ConsentRecorder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Consentement sans signature (demande de Jim, 02.11.2026) : un accord
 * oral, noté (qui l'a donné, au téléphone ou en personne, ce qui a été
 * expliqué), ou une urgence. Le vétérinaire connecté confirme qu'il en
 * prend la responsabilité ; son nom et l'heure restent.
 */
class ConsentWithoutSignatureController extends Controller
{
    public function __invoke(Request $request, Intervention $intervention, ContexteClinique $contexte, ConsentRecorder $recorder): InterventionResource
    {
        $this->authorize('update', $intervention);
        $data = $request->validate([
            'method' => ['required', Rule::in([ConsentMethod::Oral->value, ConsentMethod::Emergency->value])],
            'given_by' => ['required_if:method,oral', 'nullable', 'string', 'max:255'],
            'means' => ['required_if:method,oral', 'nullable', Rule::in(['phone', 'in_person'])],
            'explanation' => ['required_if:method,oral', 'nullable', 'string', 'max:5000'],
            'responsibility' => ['accepted'],
        ], [
            'given_by.required_if' => 'Dites qui a donné son accord.',
            'means.required_if' => 'Dites si l’accord a été donné au téléphone ou en personne.',
            'explanation.required_if' => 'Dites ce qui a été expliqué.',
            'responsibility.accepted' => 'Confirmez que vous en prenez la responsabilité.',
        ]);

        if ($intervention->estFinie()) {
            throw ValidationException::withMessages(['consentement' => 'L’intervention est terminée.']);
        }

        $employee = $contexte->employe();
        if ($employee === null) {
            throw ValidationException::withMessages(['responsibility' => 'Seul un membre de l’équipe connecté peut en prendre la responsabilité.']);
        }

        $recorder->withoutSignature($intervention, ConsentMethod::from($data['method']), $data, $employee);

        return new InterventionResource($intervention->fresh()->load(InterventionController::relations()));
    }
}
