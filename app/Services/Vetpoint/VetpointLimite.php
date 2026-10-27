<?php

namespace App\Services\Vetpoint;

use RuntimeException;

/** Vetpoint limite les appels (429 : 50 par minute le jour, 100 la nuit). */
class VetpointLimite extends RuntimeException {}
