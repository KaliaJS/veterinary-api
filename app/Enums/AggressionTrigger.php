<?php

namespace App\Enums;

/** Agressif dans quels cas (précision de l'alerte, 02.11.2026) */
enum AggressionTrigger: string
{
    case Handling = 'handling';
    case Injections = 'injections';
    case Men = 'men';
    case Women = 'women';
    case Dogs = 'dogs';
    case Cats = 'cats';
    case Pain = 'pain';
}
