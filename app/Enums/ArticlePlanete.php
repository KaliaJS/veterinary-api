<?php

namespace App\Enums;

/**
 * Objets de la boutique de la planète (jeu de l'accueil de l'espace
 * client, 16.10.2026) : achetés une fois, posés pour toujours sur la
 * planète du propriétaire. Prix TTC en centimes.
 */
enum ArticlePlanete: string
{
    case Balle = 'balle';
    case Gamelle = 'gamelle';
    case Lanterne = 'lanterne';
    case Banc = 'banc';
    case Niche = 'niche';
    case Cerisier = 'cerisier';

    public function nom(): string
    {
        return match ($this) {
            self::Balle => 'Balle',
            self::Gamelle => 'Gamelle',
            self::Lanterne => 'Lanterne',
            self::Banc => 'Banc',
            self::Niche => 'Niche',
            self::Cerisier => 'Cerisier en fleurs',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Balle => 'Une balle rouge dans la prairie.',
            self::Gamelle => 'Une gamelle bien remplie, près de la prairie.',
            self::Lanterne => 'Une lanterne qui s’allume la nuit.',
            self::Banc => 'Un banc en bois pour regarder vos animaux.',
            self::Niche => 'Une niche en bois au toit rouge.',
            self::Cerisier => 'Un arbre en fleurs roses au bord de la prairie.',
        };
    }

    public function centimes(): int
    {
        return match ($this) {
            self::Balle => 50,
            self::Gamelle, self::Lanterne => 100,
            self::Banc => 150,
            self::Niche, self::Cerisier => 200,
        };
    }

    /** Produit de l'App Store (achat non consommable dans l'app iPhone). */
    public function produitAppStore(): string
    {
        return config('services.apple.bundle_id').'.planete.'.$this->value;
    }

    public static function depuisProduitAppStore(string $produit): ?self
    {
        $prefixe = config('services.apple.bundle_id').'.planete.';

        return str_starts_with($produit, $prefixe) ? self::tryFrom(substr($produit, strlen($prefixe))) : null;
    }
}
