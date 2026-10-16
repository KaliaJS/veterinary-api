<?php

namespace App\Enums;

/**
 * Packs de quota (PACKS du front — docs/data-model.md §2). Chaque type
 * offre trois paliers [quantité, prix CHF] ; e-mails et SMS s'ajoutent
 * au mois en cours (non reportés), le stockage est permanent.
 */
enum TypePack: string
{
    case Emails = 'emails';
    case Sms = 'sms';
    case Stockage = 'sto';

    /** @return list<array{int, int}> [quantité, prix CHF] */
    public function paliers(): array
    {
        return match ($this) {
            self::Emails => [[1000, 5], [5000, 15], [20000, 40]],
            self::Sms => [[500, 12], [1000, 20], [5000, 85]],
            self::Stockage => [[10, 10], [50, 35], [100, 60]],
        };
    }

    public function prixPour(int $quantite): ?int
    {
        foreach ($this->paliers() as [$paliers, $prix]) {
            if ($paliers === $quantite) {
                return $prix;
            }
        }

        return null;
    }

    /** Limite de la formule que le pack prolonge (FormuleSaas::limites) */
    public function limite(): string
    {
        return match ($this) {
            self::Emails => 'emails',
            self::Sms => 'sms',
            self::Stockage => 'stockage_go',
        };
    }

    public function colonneQuota(): string
    {
        return match ($this) {
            self::Emails => 'quota_extra_emails',
            self::Sms => 'quota_extra_sms',
            self::Stockage => 'quota_extra_stockage_go',
        };
    }

    public function libelle(int $quantite): string
    {
        return match ($this) {
            self::Emails => 'Quota e-mails +'.number_format($quantite, 0, ',', ' '),
            self::Sms => 'Quota SMS +'.number_format($quantite, 0, ',', ' '),
            self::Stockage => "Quota stockage +{$quantite} Go",
        };
    }
}
