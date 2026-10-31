<?php

namespace App\Enums;

/**
 * La catégorie d'une vidéo de formation (31.10.2026, demande de Jim) : une
 * liste fixe, choisie par le vendeur, qui range le catalogue en rangées.
 * L'ordre des cas est celui des rangées à l'écran.
 */
enum VideoCategory: string
{
    case Nutrition = 'nutrition';
    case Behaviour = 'behaviour';
    case DailyCare = 'daily_care';
    case FirstAid = 'first_aid';
    case Cats = 'cats';
    case Dogs = 'dogs';
    case Exotic = 'exotic';
    case Horses = 'horses';
    case Surgery = 'surgery';
    case Imaging = 'imaging';
    case Dentistry = 'dentistry';
    case Anaesthesia = 'anaesthesia';
    case ClinicLife = 'clinic_life';

    public function label(): string
    {
        return match ($this) {
            self::Nutrition => 'Alimentation',
            self::Behaviour => 'Comportement',
            self::DailyCare => 'Soins du quotidien',
            self::FirstAid => 'Premiers secours',
            self::Cats => 'Chats',
            self::Dogs => 'Chiens',
            self::Exotic => 'NAC',
            self::Horses => 'Chevaux',
            self::Surgery => 'Chirurgie',
            self::Imaging => 'Imagerie',
            self::Dentistry => 'Dentisterie',
            self::Anaesthesia => 'Anesthésie',
            self::ClinicLife => 'Vie de la clinique',
        };
    }

    /**
     * La liste pour l'écran, dans l'ordre des rangées.
     *
     * @return list<array{key: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $category) => ['key' => $category->value, 'label' => $category->label()], self::cases());
    }
}
