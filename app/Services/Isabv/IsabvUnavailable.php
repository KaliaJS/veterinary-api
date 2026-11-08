<?php

namespace App\Services\Isabv;

use RuntimeException;

/** Le serveur de l'OSAV ne répond pas, ou d'une forme inattendue : on ne sait pas ce qui est arrivé. */
class IsabvUnavailable extends RuntimeException {}
