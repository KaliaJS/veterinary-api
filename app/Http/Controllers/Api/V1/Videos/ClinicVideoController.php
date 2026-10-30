<?php

namespace App\Http\Controllers\Api\V1\Videos;

/** Les vidéos que vend la clinique du jeton (espace clinique, droit organisation). */
class ClinicVideoController extends SellerVideoController
{
    protected string $as = 'clinic';
}
