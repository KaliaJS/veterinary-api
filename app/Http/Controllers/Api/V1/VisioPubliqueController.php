<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Tele\SignalVisioRequest;
use App\Models\Clinique;
use App\Models\SessionTeleconsultation;
use App\Services\Tele\ServeursIce;
use App\Services\Tele\SignalisationVisio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

/**
 * Téléconsultation maison en WebRTC (28.10.2026), côté client : sans
 * compte, par le lien secret reçu par e-mail. Le lien n'ouvre que le canal
 * de sa session, et seulement tant que l'appel n'est pas terminé.
 */
class VisioPubliqueController extends Controller
{
    public function __construct(private SignalisationVisio $signalisation) {}

    public function afficher(string $jeton): JsonResponse
    {
        $session = SessionTeleconsultation::parJeton($jeton);
        $clinique = Clinique::withoutGlobalScopes()->find($session->clinique_id);

        return response()->json(['data' => [
            'session_id' => $session->id,
            'clinique' => $clinique?->nom,
            'patient' => $session->patient()->withoutGlobalScopes()->value('nom'),
            'terminee' => ! $session->estJoignable(),
        ]]);
    }

    public function ice(string $jeton, ServeursIce $serveurs): JsonResponse
    {
        $this->joignable($jeton);

        return response()->json(['data' => ['ice_servers' => $serveurs->pour()]]);
    }

    public function signal(SignalVisioRequest $request, string $jeton): JsonResponse
    {
        $this->signalisation->envoyer(SessionTeleconsultation::parJeton($jeton), 'client', $request->validated('type'), $request->validated('donnees'));

        return response()->json(null, 202);
    }

    public function lireSignal(string $jeton, string $reference): JsonResponse
    {
        return response()->json(['data' => $this->signalisation->lire(SessionTeleconsultation::parJeton($jeton), $reference)]);
    }

    /** Autorisation Reverb du canal de la session, et d'aucun autre. */
    public function canal(Request $request, string $jeton): mixed
    {
        $session = $this->joignable($jeton);
        // Le seul canal de sa session ; un identifiant de connexion malformé est refusé, pas une erreur
        abort_unless($request->input('channel_name') === 'private-visio.'.$session->id
            && preg_match('/\A\d+\.\d+\z/', (string) $request->input('socket_id')) === 1, 403);

        return Broadcast::driver()->validAuthenticationResponse($request, true);
    }

    private function joignable(string $jeton): SessionTeleconsultation
    {
        $session = SessionTeleconsultation::parJeton($jeton);
        abort_unless($session->estJoignable(), 410, 'Cet appel est terminé.');

        return $session;
    }
}
