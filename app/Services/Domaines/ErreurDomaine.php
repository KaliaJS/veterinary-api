<?php

namespace App\Services\Domaines;

use RuntimeException;

/**
 * Refus du fournisseur de domaines, avec un message en français pour la
 * clinique ; le message du fournisseur reste au journal (23.10.2026).
 */
class ErreurDomaine extends RuntimeException {}
