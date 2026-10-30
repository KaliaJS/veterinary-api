<?php

namespace App\Http\Controllers\Api\V1\Videos;

/** L'envoi des vidéos que vend la clinique du jeton. */
class ClinicVideoUploadController extends SellerVideoUploadController
{
    protected string $as = 'clinic';
}
