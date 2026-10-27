<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\MessageContact;
use App\Services\Captcha\Turnstile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Formulaire de contact du site (décision de Jim, 27.10.2026) : au lieu
 * d'une adresse e-mail en clair, que les robots ramassent. Turnstile, limite
 * par adresse IP ; l'adresse qui reçoit ne sort jamais vers le navigateur.
 */
class ContactController extends Controller
{
    public function envoyer(Request $request, Turnstile $captcha): JsonResponse
    {
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'captcha' => ['required', 'string', 'max:4096'],
        ], [
            'captcha.required' => 'Cochez la vérification anti-robot.',
            'message.min' => 'Écrivez quelques mots de plus.',
        ]);

        if (! $captcha->verifier($donnees['captcha'], $request->ip())) {
            throw ValidationException::withMessages(['captcha' => 'La vérification anti-robot n’a pas abouti : réessayez.']);
        }

        $reception = (string) config('plateforme.contact_email');
        abort_if($reception === '', 503, 'Le formulaire de contact n’est pas disponible pour l’instant.');

        Mail::to($reception)->send(new MessageContact(trim($donnees['nom']), mb_strtolower(trim($donnees['email'])), trim($donnees['message'])));

        return response()->json(['message' => 'Message envoyé.'], 202);
    }
}
