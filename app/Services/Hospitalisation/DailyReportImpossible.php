<?php

namespace App\Services\Hospitalisation;

use RuntimeException;

/** L'IA refuse définitivement de rédiger (clé refusée, crédit épuisé, réponse illisible) : pas de nouvel essai. */
final class DailyReportImpossible extends RuntimeException {}
