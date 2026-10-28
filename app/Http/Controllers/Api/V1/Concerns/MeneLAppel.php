<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Auth\ContexteClinique;
use App\Models\SessionTeleconsultation;

/**
 * Un appel transféré change de mains (28.10.2026) : seul celui qui le mène
 * se relie au client, renvoie le lien, transfère ou termine l'appel. Un
 * ancien poste (relève manquée, page rechargée) reçoit 409 et se retire.
 */
trait MeneLAppel
{
    protected function assurerMeneLAppel(SessionTeleconsultation $session): void
    {
        if ($session->mene_par_id !== null && $session->mene_par_id !== app(ContexteClinique::class)->employe()?->id) {
            abort(409, ($session->menePar()->withoutGlobalScopes()->value('nom') ?? 'Un collègue').' mène cet appel.');
        }
    }
}
