<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\MessageContact;
use App\Services\Captcha\Turnstile;
use App\Support\CleIp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
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
        // Connecté (jeton valable) : sans Turnstile, que l'app iPhone ne peut
        // pas afficher ; ses envois sont limités par compte (décision de Jim)
        $connecte = $request->user('sanctum') !== null;

        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'captcha' => [$connecte ? 'nullable' : 'required', 'string', 'max:4096'],
        ], [
            // L'API n'a pas de traductions : chaque message en français
            'nom.required' => 'Indiquez votre nom.',
            'nom.string' => 'Indiquez votre nom.',
            'nom.max' => 'Ce nom est trop long.',
            'email.required' => 'Indiquez votre adresse e-mail.',
            'email.email' => 'Cette adresse e-mail n’est pas valable.',
            'email.max' => 'Cette adresse e-mail est trop longue.',
            'message.required' => 'Écrivez votre message.',
            'message.string' => 'Écrivez votre message.',
            'message.min' => 'Écrivez quelques mots de plus.',
            'message.max' => 'Ce message est trop long (5000 caractères au plus).',
            'captcha.required' => 'Cochez la vérification anti-robot.',
            'captcha.string' => 'La vérification anti-robot n’a pas abouti : réessayez.',
            'captcha.max' => 'La vérification anti-robot n’a pas abouti : réessayez.',
        ]);

        if (! $connecte && ! $captcha->verifier($donnees['captcha'], $request->ip())) {
            throw ValidationException::withMessages(['captcha' => 'La vérification anti-robot n’a pas abouti : réessayez.']);
        }

        $reception = (string) config('plateforme.contact_email');
        abort_if($reception === '', 503, 'Le formulaire de contact n’est pas disponible pour l’instant.');

        // Le nom tient sur une ligne : aucun caractère de contrôle ni
        // séparateur de ligne Unicode n'invente de ligne dans le message
        $nom = trim((string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $donnees['nom']));
        if ($nom === '') {
            throw ValidationException::withMessages(['nom' => 'Indiquez votre nom.']);
        }

        // Limite comptée seulement pour un message accepté : une faute de
        // saisie ne coûte rien (28.10.2026). Par compte une fois connecté,
        // sinon par adresse IP (préfixe /64 en IPv6)
        $utilisateur = $request->user('sanctum');
        $cle = $utilisateur !== null ? 'compte:'.$utilisateur->getAuthIdentifier() : 'ip:'.CleIp::de($request);
        foreach ([
            ['contact:'.$cle, 5, 3600, 'Trop de messages envoyés : réessayez dans une heure.'],
            ['contact-jour:'.$cle, 20, 86400, 'Trop de messages envoyés aujourd’hui : réessayez demain.'],
        ] as [$limiteur, $max, $duree, $refus]) {
            if (RateLimiter::tooManyAttempts($limiteur, $max)) {
                return response()->json(['message' => $refus], 429);
            }
        }
        RateLimiter::hit('contact:'.$cle, 3600);
        RateLimiter::hit('contact-jour:'.$cle, 86400);

        // Envoyé depuis un compte : le message dit lequel (l'adresse de réponse est libre)
        $compte = $utilisateur !== null ? 'n° '.$utilisateur->getAuthIdentifier().', '.$utilisateur->email : null;

        try {
            Mail::to($reception)->send(new MessageContact($nom, mb_strtolower(trim($donnees['email'])), trim($donnees['message']), $compte));
        } catch (Throwable $e) {
            // Resend en panne : un message clair, l'adresse de réception reste ici
            report($e);
            abort(503, 'Votre message n’est pas parti : réessayez dans quelques minutes.');
        }

        return response()->json(['message' => 'Message envoyé.'], 202);
    }
}
