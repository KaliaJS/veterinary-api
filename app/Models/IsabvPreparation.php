<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Une préparation de la liste de l'OSAV, commune à toutes les cliniques
 * (08.11.2026). Se déclare si `must_transfer` (« transferISABV »).
 */
class IsabvPreparation extends Model
{
    protected $fillable = ['code', 'name_fr', 'name_de', 'atc_code', 'unit_code', 'unit_label_fr', 'category_code', 'category_label_fr', 'route_code', 'preparation_type', 'must_transfer', 'has_critical_substance'];

    protected function casts(): array
    {
        return ['must_transfer' => 'boolean', 'has_critical_substance' => 'boolean'];
    }

    /** Le code d'une préparation suisse : n° d'autorisation Swissmedic (5 chiffres) et séquence (2) */
    public static function swissCode(string $authorisation, string $sequence): ?string
    {
        $authorisation = ltrim(trim($authorisation), '0');
        $sequence = trim($sequence);
        if ($authorisation === '' || ! ctype_digit($authorisation) || strlen($authorisation) > 5 || $sequence === '' || ! ctype_digit($sequence) || strlen($sequence) > 2) {
            return null;
        }

        return str_pad($authorisation, 5, '0', STR_PAD_LEFT).'-'.str_pad($sequence, 2, '0', STR_PAD_LEFT);
    }
}
