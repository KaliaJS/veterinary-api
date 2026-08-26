<?php

namespace App\Enums;

enum TypeSalle: string
{
    case Consultation = 'consultation';
    case Bloc = 'bloc';
    case Imagerie = 'imagerie';
}
