<?php

namespace App\Enums;

/**
 * Ce qu'est une ligne de `videos` (31.10.2026, séries demandées par Jim) :
 * une vidéo seule, une série (sans fichiers : elle porte le prix, le
 * public, la catégorie, l'affiche et Plus de ses épisodes), ou un épisode
 * d'une série (ses fichiers, son titre, sa description, son image ; ni
 * prix ni public à lui). Le vendeur choisit : une vidéo seule ou une
 * série, jamais les deux pour un même contenu.
 */
enum VideoKind: string
{
    case Video = 'video';
    case Series = 'series';
    case Episode = 'episode';
}
