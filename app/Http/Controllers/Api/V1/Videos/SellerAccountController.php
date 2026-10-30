<?php

namespace App\Http\Controllers\Api\V1\Videos;

use App\Http\Controllers\Controller;
use App\Services\Videos\SellerAccounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le compte de paiement d'un vétérinaire qui vend en son nom (31.10.2026) :
 * ouvert et géré dans Veterinary, par les composants intégrés.
 */
class SellerAccountController extends Controller
{
    public function __construct(private SellerAccounts $accounts) {}

    public function show(Request $request): JsonResponse
    {
        $user = $this->accounts->refresh($request->user());

        return response()->json(['data' => ['exists' => $user->stripe_account_id !== null, 'ready' => $user->stripe_account_ready_at !== null]]);
    }

    /** Ouvre le compte s'il n'existe pas, et rend la session des composants intégrés. */
    public function store(Request $request): JsonResponse
    {
        $user = $this->accounts->open($request->user());

        return response()->json(['data' => [
            'exists' => true,
            'ready' => $user->stripe_account_ready_at !== null,
            'client_secret' => $this->accounts->session($user),
            'publishable_key' => config('services.stripe.key'),
        ]]);
    }
}
