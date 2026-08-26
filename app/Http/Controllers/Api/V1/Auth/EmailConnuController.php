<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\VerifierEmailRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Premier pas du front (checkEmail) : le compte existe-t-il ?
 * Contrat api/auth.js : { known }.
 */
class EmailConnuController extends Controller
{
    public function __invoke(VerifierEmailRequest $request): JsonResponse
    {
        return response()->json([
            'known' => User::where('email', $request->string('email'))->exists(),
        ]);
    }
}
