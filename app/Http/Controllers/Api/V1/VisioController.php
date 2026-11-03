<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Api\V1\Concerns\MeneLAppel;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Tele\SignalVisioRequest;
use App\Mail\LienVisio;
use App\Models\Client;
use App\Models\RendezVous;
use App\Models\SessionTeleconsultation;
use App\Services\Tele\ServeursIce;
use App\Services\Tele\SignalisationVisio;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;

/**
 * Téléconsultation maison en WebRTC (28.10.2026), côté vétérinaire : le
 * lien du client par e-mail, les serveurs ICE (STUN et TURN de
 * Cloudflare), la mise en relation par l'API et Reverb.
 */
class VisioController extends Controller
{
    use MeneLAppel;

    public function __construct(private SignalisationVisio $signalisation) {}

    /** Le lien du client, envoyé à son adresse ; rendu aussi pour le copier. */
    public function lien(SessionTeleconsultation $session): JsonResponse
    {
        $this->authorize('update', $session);
        abort_unless($session->estJoignable(), 410, 'Cet appel est terminé.');
        $this->assurerMeneLAppel($session);

        $lien = $session->lienClient();
        // L'appel d'un rendez-vous pris par l'ancien propriétaire reste à lui (03.11.2026)
        $rendezVous = $session->rendez_vous_id ? RendezVous::withoutGlobalScopes()->find($session->rendez_vous_id) : null;
        $client = $rendezVous?->client_id !== null && $rendezVous->client_id !== $session->patient?->client_id
            ? Client::withoutGlobalScopes()->find($rendezVous->client_id)
            : $session->patient?->client;
        $email = $client?->email;
        if ($email) {
            Mail::to($email)->send(new LienVisio($session->load('patient'), app(ContexteClinique::class)->clinique(), $lien));
            $session->forceFill(['lien_envoye_le' => now()])->save();
        }

        return response()->json(['data' => ['url' => $lien, 'envoye_a' => $email ?: null]]);
    }

    public function ice(SessionTeleconsultation $session, ServeursIce $serveurs): JsonResponse
    {
        $this->authorize('view', $session);
        abort_unless($session->estJoignable(), 410, 'Cet appel est terminé.');
        $this->assurerMeneLAppel($session);

        return response()->json(['data' => ['ice_servers' => $serveurs->pour()]]);
    }

    public function signal(SignalVisioRequest $request, SessionTeleconsultation $session): JsonResponse
    {
        $this->authorize('update', $session);
        $this->assurerMeneLAppel($session);
        $this->signalisation->envoyer($session, 'veterinaire', $request->validated('type'), $request->validated('donnees'));

        return response()->json(null, 202);
    }

    public function lireSignal(SessionTeleconsultation $session, string $reference): JsonResponse
    {
        $this->authorize('view', $session);

        return response()->json(['data' => $this->signalisation->lire($session, $reference)]);
    }
}
