<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * L'accord de la personne pour paraître sur le site public de son
 * organisation (nLPD, 23.10.2026) : son nom, son poste et sa photo. Elle
 * seule le donne ou le retire, pour la fiche de la clinique de sa session.
 */
class AccordSiteController extends Controller
{
    public function __invoke(Request $request, ContexteClinique $contexte): JsonResponse
    {
        $accord = $request->validate(['accord' => ['required', 'boolean']])['accord'];
        $employe = $contexte->employe() ?? throw ValidationException::withMessages(['accord' => 'Aucune fiche d’équipe dans cette session.']);

        $employe->forceFill(['accord_site_le' => $accord ? ($employe->accord_site_le ?? now()) : null])->save();

        return response()->json(['data' => ['accord' => $employe->accord_site_le !== null, 'le' => $employe->accord_site_le?->toIso8601String()]]);
    }
}
