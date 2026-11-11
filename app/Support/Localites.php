<?php

namespace App\Support;

/**
 * Le répertoire officiel des localités de swisstopo, hors ligne
 * (resources/data/localites.json : source, date et licence dans le README
 * à côté) : un NPA donne ses localités et leur canton.
 */
final class Localites
{
    /** @return list<array{0: string, 1: string|null}> */
    public static function duNpa(?string $npa): array
    {
        /** @var array<string, list<array{0: string, 1: string|null}>> $repertoire */
        $repertoire = once(fn (): array => json_decode((string) file_get_contents(resource_path('data/localites.json')), true, flags: JSON_THROW_ON_ERROR));

        return $repertoire[(string) $npa] ?? [];
    }

    /** Le canton du NPA (celui de la localité principale), ou null. */
    public static function canton(?string $npa): ?string
    {
        return self::duNpa($npa)[0][1] ?? null;
    }
}
