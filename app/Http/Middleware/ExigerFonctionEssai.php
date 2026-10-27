<?php

namespace App\Http\Middleware;

use App\Models\JetonApi;
use App\Services\Plateforme\FonctionsEssai;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route d'une fonction en essai (`fonction:nom`, 27.10.2026) : refusée à
 * qui ne l'a pas, ni pour son compte, ni pour l'organisation de sa
 * clinique, ni pour tout le monde. L'app cache déjà le bouton ; ceci est
 * la vraie garde.
 */
class ExigerFonctionEssai
{
    public function __construct(private FonctionsEssai $fonctions) {}

    public function handle(Request $request, Closure $next, string $nom): Response
    {
        $user = $request->user();
        $jeton = $user?->currentAccessToken();

        abort_unless(
            $user !== null && $this->fonctions->estActive($nom, $user, $this->fonctions->organisationDuJeton($jeton instanceof JetonApi ? $jeton : null)),
            403,
            'Cette fonction est en essai et n’est pas encore ouverte pour vous.',
        );

        return $next($request);
    }
}
