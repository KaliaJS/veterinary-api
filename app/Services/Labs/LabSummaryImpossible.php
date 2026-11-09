<?php

namespace App\Services\Labs;

use RuntimeException;

/** La rédaction ne peut pas se faire (refus, réponse illisible) : pas de nouvel essai, le vétérinaire écrit lui-même */
class LabSummaryImpossible extends RuntimeException {}
