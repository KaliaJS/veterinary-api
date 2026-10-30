<?php

namespace App\Http\Controllers\Api\V1\Videos;

/** Les vidéos qu'un vétérinaire vend en son nom (espace du compte). */
class PersonalVideoController extends SellerVideoController
{
    protected string $as = 'me';
}
