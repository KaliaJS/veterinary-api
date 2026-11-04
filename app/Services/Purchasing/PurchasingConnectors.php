<?php

namespace App\Services\Purchasing;

/**
 * Les centrales d'achat déclarées dans config/purchasing.php, par leur clé
 * (« vetpoint »). Une clé inconnue n'a pas de connecteur.
 */
class PurchasingConnectors
{
    /** @return array<string, class-string<PurchasingConnector>> */
    public function all(): array
    {
        return array_filter((array) config('purchasing.connectors', []), fn ($class): bool => is_string($class) && is_subclass_of($class, PurchasingConnector::class));
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    public function get(?string $key): ?PurchasingConnector
    {
        $class = $key !== null ? ($this->all()[$key] ?? null) : null;

        return $class !== null ? app($class) : null;
    }
}
