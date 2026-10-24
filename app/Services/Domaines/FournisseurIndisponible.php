<?php

namespace App\Services\Domaines;

/**
 * Le fournisseur de domaines ne répond pas, ou répond par une erreur de
 * son côté (24.10.2026) : on ne sait rien du domaine, son état ne change
 * pas. Ce n'est pas un domaine qui ne pointe plus.
 */
class FournisseurIndisponible extends ErreurDomaine
{
    public function __construct()
    {
        parent::__construct('Le service des domaines ne répond pas. Réessayez dans quelques minutes.');
    }
}
