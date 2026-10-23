<?php

namespace App\Services\Diagnostic;

use RuntimeException;

/** Claude refuse ou ne peut pas répondre : inutile de réessayer, rien n'est facturé. */
final class SuggestionImpossible extends RuntimeException {}
