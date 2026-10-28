<?php

namespace App\Services\Messages;

use RuntimeException;

/** Un SMS que Twilio refuse (numéro invalide, crédit épuisé…) ou qui ne peut pas partir (clé absente). */
class SmsRefuse extends RuntimeException {}
