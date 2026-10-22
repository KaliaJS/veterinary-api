<?php

namespace App\Enums;

enum DrapeauRendezVous: string
{
    case AJeun = 'ajeun';
    case ManipulationPrudente = 'mord';
    case Isolement = 'iso';
    case NouveauClient = 'neuf';
    /** Acompte payé en ligne à la réservation */
    case Acompte = 'acompte';
}
