<?php

namespace App\Enums;

/** Vie d'une suggestion diagnostique : demandée (Claude réfléchit), rendue, ou échouée (rien n'est facturé). */
enum StatutSuggestionDiagnostique: string
{
    case EnCours = 'en_cours';
    case Prete = 'prete';
    case Erreur = 'erreur';
}
