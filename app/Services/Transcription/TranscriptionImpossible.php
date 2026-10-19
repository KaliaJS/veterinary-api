<?php

namespace App\Services\Transcription;

use RuntimeException;

/** Le fournisseur refuse la transcription : inutile de réessayer tout de suite. */
class TranscriptionImpossible extends RuntimeException {}
