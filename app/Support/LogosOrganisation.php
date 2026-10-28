<?php

namespace App\Support;

use App\Models\SiteWeb;

/**
 * Le logo d'une organisation (décision de Jim, 28.10.2026) : un seul, celui
 * de son site web. Lu une fois par requête et par organisation : une liste
 * de pièces ne relit pas le site pour chacune (instance « scoped »).
 */
final class LogosOrganisation
{
    /** @var array<int, ?string> */
    private array $logos = [];

    public function pour(?int $organisationId): ?string
    {
        if ($organisationId === null) {
            return null;
        }
        if (! array_key_exists($organisationId, $this->logos)) {
            $this->logos[$organisationId] = SiteWeb::query()->withoutGlobalScopes()->where('organisation_id', $organisationId)->first()?->logoUrl();
        }

        return $this->logos[$organisationId];
    }
}
