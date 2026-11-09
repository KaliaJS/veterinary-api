<?php

namespace App\Enums;

/**
 * Moyen d'un règlement de facture (09.11.2026) : ceux de la caisse et le
 * virement (MoyenPaiement), et l'acompte payé en ligne à la réservation,
 * qui n'est jamais passé par la caisse.
 */
enum SettlementMethod: string
{
    case Tpe = 'tpe';
    case Twint = 'twint';
    case Especes = 'cash';
    case Virement = 'virement';
    case Acompte = 'acompte';

    public static function fromPayment(MoyenPaiement $moyen): self
    {
        return self::from($moyen->value);
    }

    /** Passé par la caisse : compté à l'arrêté et à la clôture */
    public function isCashDesk(): bool
    {
        return in_array($this, [self::Tpe, self::Twint, self::Especes], true);
    }

    /** @return list<string> */
    public static function cashDeskValues(): array
    {
        return [self::Tpe->value, self::Twint->value, self::Especes->value];
    }

    public function label(): string
    {
        return match ($this) {
            self::Tpe => 'Carte',
            self::Twint => 'TWINT',
            self::Especes => 'Espèces',
            self::Virement => 'Virement',
            self::Acompte => 'Acompte en ligne',
        };
    }
}
