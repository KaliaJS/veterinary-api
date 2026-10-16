<?php

namespace App\Paiements\AppStore;

use RuntimeException;

/** Donnée App Store refusée : signature, chaîne de certificats ou contenu. */
class JwsInvalide extends RuntimeException {}
