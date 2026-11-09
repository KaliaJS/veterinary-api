<?php

namespace App\Services\Imports;

use RuntimeException;

/** La découpe ne peut pas se faire (refus, réponse illisible) : pas de nouvel essai, la découpe se fait à la main */
class RecordSplitImpossible extends RuntimeException {}
