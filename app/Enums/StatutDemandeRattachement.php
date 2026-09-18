<?php

namespace App\Enums;

/** Demande de rattachement d'un animal à un compte : en attente de la clinique, acceptée, refusée. */
enum StatutDemandeRattachement: string
{
    case Attente = 'attente';
    case Acceptee = 'acceptee';
    case Refusee = 'refusee';
}
