<?php

namespace App\Services\Vetpoint;

use RuntimeException;

/** Vetpoint refuse l'accès (403) : les CGV de l'interface ne sont pas acceptées dans le profil (OpenAPI). */
class VetpointCgv extends RuntimeException {}
