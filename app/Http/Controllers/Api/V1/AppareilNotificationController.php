<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppareilNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * La montre donne son jeton d'Apple (28.10.2026) pour être réveillée quand
 * la journée change. Un jeton appartient au dernier compte qui l'a donné.
 */
class AppareilNotificationController extends Controller
{
    public function enregistrer(Request $request): Response
    {
        $donnees = $request->validate([
            'jeton' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64,200}$/'],
            'environnement' => ['required', 'in:sandbox,production'],
            'plateforme' => ['required', 'in:watchos,ios'],
        ]);
        AppareilNotification::query()->updateOrCreate(
            ['jeton' => strtolower($donnees['jeton'])],
            ['user_id' => $request->user()->id, 'environnement' => $donnees['environnement'], 'plateforme' => $donnees['plateforme']],
        );

        return response()->noContent();
    }
}
