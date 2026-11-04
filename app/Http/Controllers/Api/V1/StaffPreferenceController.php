<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Preferences\UpdateStaffPreferencesRequest;
use App\Http\Resources\V1\StaffPreferenceResource;
use App\Models\StaffPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Les préférences de la personne du jeton, dans la clinique du jeton
 * (demande de Jim, 04.11.2026) : outils favoris et choix de masquer les
 * autres. Toujours celles du compte connecté : aucune route ne lit ni ne
 * change celles d'un autre.
 */
class StaffPreferenceController extends Controller
{
    public function show(Request $request): StaffPreferenceResource
    {
        return new StaffPreferenceResource(StaffPreference::for($request->user()->id));
    }

    /** La première fois, la ligne naît : toujours 200, c'est une modification pour l'app ; deux premiers envois simultanés ne cassent pas */
    public function update(UpdateStaffPreferencesRequest $request): JsonResponse
    {
        // updateOrCreate passe par createOrFirst : si une autre requête crée la
        // ligne au même moment, l'index unique est rattrapé et sa ligne reprise
        $preferences = StaffPreference::query()->updateOrCreate(['user_id' => $request->user()->id], $request->validated());

        return (new StaffPreferenceResource($preferences))->response()->setStatusCode(200);
    }
}
