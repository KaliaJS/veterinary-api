<?php

namespace App\Enums;

enum TypeRendezVous: string
{
    case Consultation = 'consult';
    case Vaccin = 'vaccin';
    case Chirurgie = 'chir';
    case Controle = 'ctrl';
    case Imagerie = 'imagerie';
    case Urgence = 'urgence';
    case Euthanasie = 'euth';
    case Teleconsultation = 'tele';
    case Tampon = 'tampon';
}
