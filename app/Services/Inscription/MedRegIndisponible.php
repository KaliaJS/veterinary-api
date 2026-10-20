<?php

namespace App\Services\Inscription;

use RuntimeException;

/** MedReg injoignable ou réponse illisible : la vérification se fera à la main. */
final class MedRegIndisponible extends RuntimeException {}
