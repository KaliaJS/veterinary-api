<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Facturation\AppairerTerminalRequest;
use App\Http\Resources\V1\TerminalPaiementResource;
use App\Models\TerminalPaiement;
use App\Paiements\ErreurPaiement;
use App\Services\Paiements\TerminauxPaiement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Terminaux de paiement de la clinique du jeton : liste (avec l'état en
 * ligne lu chez Stripe), appairage par code, retrait.
 */
class TerminalPaiementController extends Controller
{
    public function __construct(private TerminauxPaiement $terminaux) {}

    public function index(): JsonResponse
    {
        $terminaux = TerminalPaiement::query()->orderBy('libelle')->get();

        return response()->json([
            'data' => $terminaux->map(fn (TerminalPaiement $terminal) => new TerminalPaiementResource(
                $terminal,
                $this->terminaux->estEnLigne($terminal),
            )),
        ]);
    }

    public function store(AppairerTerminalRequest $request): TerminalPaiementResource
    {
        try {
            $terminal = $this->terminaux->appairer(
                app(ContexteClinique::class)->clinique(),
                $request->validated('code_appairage'),
                $request->validated('libelle'),
            );
        } catch (ErreurPaiement $e) {
            // Le plus souvent un code expiré ou mal recopié
            throw ValidationException::withMessages(['code_appairage' => $e->estGenerique()
                ? 'Ce code n’est pas reconnu : générez-en un nouveau sur le terminal et saisissez-le.'
                : $e->getMessage()]);
        }

        return new TerminalPaiementResource($terminal, $this->terminaux->estEnLigne($terminal));
    }

    public function destroy(TerminalPaiement $terminal): Response
    {
        try {
            $this->terminaux->retirer($terminal);
        } catch (ErreurPaiement $e) {
            throw ValidationException::withMessages(['terminal' => $e->getMessage()]);
        }

        return response()->noContent();
    }
}
