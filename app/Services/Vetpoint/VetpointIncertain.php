<?php

namespace App\Services\Vetpoint;

use RuntimeException;

/**
 * Vetpoint rend des commandes, mais aucune ne porte de note interne : on ne
 * peut pas savoir si la nôtre est partie (28.10.2026). Rien ne repart.
 */
class VetpointIncertain extends RuntimeException {}
