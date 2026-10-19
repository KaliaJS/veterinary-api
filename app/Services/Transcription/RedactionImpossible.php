<?php

namespace App\Services\Transcription;

use RuntimeException;

/** Le rédacteur refuse ou rend une réponse inutilisable : inutile de réessayer tout de suite. */
class RedactionImpossible extends RuntimeException {}
