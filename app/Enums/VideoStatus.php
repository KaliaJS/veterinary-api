<?php

namespace App\Enums;

/** Vie d'une vidéo de formation (31.10.2026) : brouillon, en vente, retirée de la vente. */
enum VideoStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Withdrawn = 'withdrawn';
}
