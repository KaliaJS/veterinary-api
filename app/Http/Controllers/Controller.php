<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Convention de pagination de l'API : `per_page` (défaut 25,
     * plafonné à 100) + `page`, réponses au format paginé standard
     * de Laravel (data / links / meta).
     */
    protected function parPage(Request $request, int $defaut = 25): int
    {
        return min(max($request->integer('per_page', $defaut), 1), 100);
    }
}
