<?php

namespace App\Services\Vetpoint;

use RuntimeException;

/** Vetpoint refuse la clé (401, 403) : renouvelée ou fausse. */
class VetpointCleRefusee extends RuntimeException {}
