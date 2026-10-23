<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Un montant s'écrit comme dans l'app : « CHF 1’234.50 ». */
class ChfTest extends TestCase
{
    public function test_un_montant_s_ecrit_a_la_suisse(): void
    {
        $this->assertSame('CHF 1’234.50', chf(1234.5));
        $this->assertSame('CHF 0.00', chf(null));
        $this->assertSame('CHF 1’250’000.00', chf('1250000'));
        $this->assertSame('CHF -12.50', chf(-12.5));
    }
}
