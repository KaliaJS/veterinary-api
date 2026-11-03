<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Http\Controllers\Controller;
use App\Services\Clients\OwnerSpaceInvitations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /espace/invitations-fiche/accepter — le lien de l'e-mail « Votre
 * espace Veterinary » (03.11.2026), ouvert connecté : la fiche rejoint
 * l'espace si le compte a l'adresse invitée, prouvée, encore celle de la
 * fiche, et si le lien est encore valable. Le jeton vient dans le corps,
 * jamais dans l'adresse. Rend le nom de la clinique qui a invité.
 */
class OwnerInvitationController extends Controller
{
    public function accept(Request $request): JsonResponse
    {
        $token = $request->validate(
            ['jeton' => ['required', 'string', 'regex:/^[A-Za-z0-9]{48}$/']],
            // Un lien coupé par la messagerie : un message clair, en français
            ['jeton.*' => 'Ce lien est incomplet : ouvrez de nouveau le lien reçu par e-mail.'],
        )['jeton'];
        ['client' => $client, 'clinic' => $clinic] = OwnerSpaceInvitations::accept($token, $request->user());

        return response()->json(['data' => [
            'client_id' => $client->id,
            'clinique' => $clinic ?? $client->organisation()->withoutGlobalScopes()->value('nom'),
        ]]);
    }
}
