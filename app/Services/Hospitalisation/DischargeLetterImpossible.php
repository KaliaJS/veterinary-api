<?php

namespace App\Services\Hospitalisation;

use RuntimeException;

/** Le rédacteur de la lettre de sortie refuse ou rend une réponse inutilisable : pas de nouvel essai. */
final class DischargeLetterImpossible extends RuntimeException {}
