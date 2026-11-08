<?php

namespace App\Services\Isabv;

use RuntimeException;

/** L'OSAV refuse l'IDE, le complément ou le token de la clinique (création du JWT). */
class IsabvTokenRefused extends RuntimeException {}
