<?php

namespace App\Enums;

/**
 * À qui une vidéo est vendue (31.10.2026) : les vétérinaires vérifiés, les
 * clients (tous les propriétaires de Veterinary, jamais ceux d'une seule
 * clinique), ou les deux.
 */
enum VideoAudience: string
{
    case Vets = 'vets';
    case Owners = 'owners';
    case Both = 'both';

    public function includesOwners(): bool
    {
        return $this !== self::Vets;
    }

    public function includesVets(): bool
    {
        return $this !== self::Owners;
    }
}
