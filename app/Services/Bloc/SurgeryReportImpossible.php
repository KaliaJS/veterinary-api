<?php

namespace App\Services\Bloc;

use RuntimeException;

/** Le rédacteur refuse ou rend une réponse inutilisable : pas de nouvel essai. */
final class SurgeryReportImpossible extends RuntimeException {}
