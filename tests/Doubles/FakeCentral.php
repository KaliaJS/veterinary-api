<?php

namespace Tests\Doubles;

use App\Models\CommandeFournisseur;
use App\Models\CommandeFournisseurLigne;
use App\Services\Purchasing\PurchasingConnector;
use App\Services\Purchasing\PurchasingFailure;

/**
 * Une centrale d'achat de test, branchée par la configuration comme le
 * serait n'importe quelle autre (rien de Vetpoint en dur) : elle dit
 * indisponibles les articles qu'on lui donne et garde ce qui lui est envoyé.
 */
class FakeCentral implements PurchasingConnector
{
    /** @var list<int> articles indisponibles chez elle */
    public static array $unavailable = [];

    /** @var list<array{order: int, lines: array<int, int>, choice: array<string, mixed>}> */
    public static array $sent = [];

    public static bool $isLinked = true;

    public static ?string $failure = null;

    public static function reset(): void
    {
        self::$unavailable = [];
        self::$sent = [];
        self::$isLinked = true;
        self::$failure = null;
    }

    public function name(): string
    {
        return 'Centrale Test';
    }

    public function isLinked(int $clinicId): bool
    {
        return self::$isLinked;
    }

    public function unavailable(CommandeFournisseur $order): array
    {
        return $order->lignes()->get()
            ->filter(fn (CommandeFournisseurLigne $line): bool => in_array((int) $line->produit_id, self::$unavailable, true))
            ->mapWithKeys(fn (CommandeFournisseurLigne $line): array => [$line->id => 'Indisponible chez Centrale Test'])
            ->all();
    }

    public function send(CommandeFournisseur $order, array $choice = []): void
    {
        if (self::$failure !== null) {
            throw new PurchasingFailure(self::$failure);
        }
        self::$sent[] = [
            'order' => $order->id,
            'lines' => $order->lignes()->pluck('quantite', 'produit_id')->map(fn ($q): int => (int) $q)->all(),
            'choice' => ['adresse_id' => $choice['adresse_id'] ?? null, 'livraison' => $choice['livraison'] ?? 'standard', 'date_souhaitee' => $choice['date_souhaitee'] ?? null],
        ];
        $order->forceFill(['statut' => 'sent', 'envoyee_le' => now()])->save();
    }

    public function addresses(int $clinicId): array
    {
        return [['id' => 'A1', 'libelle' => 'Clinique, Rue du Lac 1, 1000 Lausanne', 'par_defaut' => true]];
    }

    public function pendingSend(CommandeFournisseur $order): ?string
    {
        return null;
    }
}
