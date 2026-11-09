<?php

namespace App\Services\Referrals;

use RuntimeException;

/** Le rédacteur de la lettre de référence refuse ou rend une réponse inutilisable : pas de nouvel essai. */
final class ReferralLetterImpossible extends RuntimeException {}
