<?php

namespace App\Enums;

/**
 * Formules d'abonnement (PLANS du front — docs/data-model.md §2).
 * `null` dans les limites vaut « illimité ». Les limites plafonnent les
 * cliniques, les employés ACTIFS (accès non suspendu), les quotas
 * mensuels et le stockage.
 */
enum FormuleSaas: string
{
    case Individuel = 'individuel';
    case Cabinet = 'cabinet';
    case Hopital = 'hopital';

    public function nom(): string
    {
        return match ($this) {
            self::Individuel => 'Veterinary Pro Individuel',
            self::Cabinet => 'Veterinary Pro Cabinet',
            self::Hopital => 'Veterinary Pro Hôpital',
        };
    }

    public function prixMensuel(): int
    {
        return match ($this) {
            self::Individuel => 99,
            self::Cabinet => 249,
            self::Hopital => 999,
        };
    }

    /** Stockage offert à chaque organisation, quelle que soit la formule (décision de Jim, 28.10.2026) */
    public const int STOCKAGE_GO = 1000;

    /** @return array{cliniques: ?int, employes: ?int, emails: ?int, sms: ?int, patients: ?int, stockage_go: ?int} */
    public function limites(): array
    {
        return match ($this) {
            self::Individuel => ['cliniques' => 1, 'employes' => 2, 'emails' => 500, 'sms' => 100, 'patients' => 800, 'stockage_go' => self::STOCKAGE_GO],
            self::Cabinet => ['cliniques' => 5, 'employes' => 30, 'emails' => 5000, 'sms' => 1000, 'patients' => 10000, 'stockage_go' => self::STOCKAGE_GO],
            self::Hopital => ['cliniques' => null, 'employes' => null, 'emails' => null, 'sms' => null, 'patients' => null, 'stockage_go' => self::STOCKAGE_GO],
        };
    }
}
