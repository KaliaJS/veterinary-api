<?php

namespace App\Services\Vetpoint;

use RuntimeException;

/** Vetpoint ne répond pas (réseau, délai, erreur 5xx ou réponse inattendue). */
class VetpointIndisponible extends RuntimeException {}
