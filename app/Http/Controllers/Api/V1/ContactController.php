<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\MessageContact;
use App\Services\Captcha\Turnstile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

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
            // L'API n'a pas de traductions : chaque message en français
            'nom.required' => 'Indiquez votre nom.',
            'nom.max' => 'Ce nom est trop long.',
            'email.required' => 'Indiquez votre adresse e-mail.',
            'email.email' => 'Cette adresse e-mail n’est pas valable.',
            'email.max' => 'Cette adresse e-mail est trop longue.',
            'message.required' => 'Écrivez votre message.',
            'message.min' => 'Écrivez quelques mots de plus.',
            'message.max' => 'Ce message est trop long (5000 caractères au plus).',
            'captcha.required' => 'Cochez la vérification anti-robot.',
        ]);

        if (! $captcha->verifier($donnees['captcha'], $request->ip())) {
            throw ValidationException::withMessages(['captcha' => 'La vérification anti-robot n’a pas abouti : réessayez.']);
        }

        $reception = (string) config('plateforme.contact_email');
        abort_if($reception === '', 503, 'Le formulaire de contact n’est pas disponible pour l’instant.');

        // Le nom tient sur une ligne : un retour à la ligne n'invente pas de ligne dans le message
        $nom = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $donnees['nom']));

        try {
            Mail::to($reception)->send(new MessageContact($nom, mb_strtolower(trim($donnees['email'])), trim($donnees['message'])));
        } catch (Throwable $e) {
            // Resend en panne : un message clair, l'adresse de réception reste ici
            report($e);
            abort(503, 'Votre message n’est pas parti : réessayez dans quelques minutes.');
        }

        return response()->json(['message' => 'Message envoyé.'], 202);
    }
}
