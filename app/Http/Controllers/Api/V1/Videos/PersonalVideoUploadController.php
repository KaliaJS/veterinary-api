<?php

namespace App\Http\Controllers\Api\V1\Videos;

/** L'envoi des vidéos qu'un vétérinaire vend en son nom. */
class PersonalVideoUploadController extends SellerVideoUploadController
{
    protected string $as = 'me';
}
