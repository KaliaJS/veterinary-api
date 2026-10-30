<?php

namespace App\Enums;

/** Les fichiers d'une vidéo : en cours d'envoi, vérifiés par le serveur, ou refusés. */
enum VideoFilesStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Rejected = 'rejected';
}
