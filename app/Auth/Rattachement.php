<?php

namespace App\Auth;

use App\Models\Clinique;
use App\Models\Employe;

/**
 * Une porte d'entrée d'un compte : sa fiche dans une organisation et une
 * des cliniques où elle est affectée. Un employé de trois cliniques a
 * trois rattachements, une seule fiche.
 */
final readonly class Rattachement
{
    public function __construct(
        public Employe $employe,
        public Clinique $clinique,
    ) {}
}
