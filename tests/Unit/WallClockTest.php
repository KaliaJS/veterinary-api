<?php

namespace Tests\Unit;

use App\Support\WallClock;
use PHPUnit\Framework\TestCase;

/** L'heure murale lue en masse (06.11.2026) : les changements d'heure de Zurich compris. */
class WallClockTest extends TestCase
{
    public function test_l_heure_murale_suit_les_changements_d_heure(): void
    {
        $zurich = new WallClock('Europe/Zurich');

        // Hiver : UTC+1 ; été : UTC+2
        $this->assertSame('2026-01-15 10:00', gmdate('Y-m-d H:i', $zurich->local('2026-01-15 09:00:00')));
        $this->assertSame('2026-07-15 11:00', gmdate('Y-m-d H:i', $zurich->local('2026-07-15 09:00:00')));
        // Le 29 mars 2026 à 1 h UTC, on passe de 2 h à 3 h
        $this->assertSame('01:59', gmdate('H:i', $zurich->local('2026-03-29 00:59:00')));
        $this->assertSame('03:00', gmdate('H:i', $zurich->local('2026-03-29 01:00:00')));
        // 23 h 30 UTC le 31 octobre : déjà le 1er novembre à Zurich
        $this->assertSame('2026-11-01', $zurich->day('2026-10-31 23:30:00'));
        $this->assertSame('2026-11-05', (new WallClock('Pacific/Kiritimati'))->day('2026-11-04 15:00:00'));
    }
}
