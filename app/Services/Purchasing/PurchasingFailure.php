<?php

namespace App\Services\Purchasing;

use RuntimeException;

/** Une centrale qui n'a pas pris le panier, avec ce qu'il faut dire à la clinique. */
class PurchasingFailure extends RuntimeException {}
