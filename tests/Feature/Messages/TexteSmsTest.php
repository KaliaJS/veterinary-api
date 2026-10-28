<?php

namespace Tests\Feature\Messages;

use App\Support\TexteSms;
use Tests\TestCase;

/**
 * Le texte d'un SMS (vérification du 28.10.2026) : Twilio facture chaque
 * segment. Un seul caractère hors de l'alphabet SMS (« ô » de « contrôle »)
 * fait passer un SMS de 160 à 70 caractères : nos textes s'écrivent dans
 * l'alphabet SMS, et le quota compte les segments.
 */
class TexteSmsTest extends TestCase
{
    public function test_les_accents_hors_alphabet_sms_sont_remplaces(): void
    {
        $this->assertSame('Deux Rives : controle de Felix le 12.11.2026 à 14:30.', TexteSms::versGsm('Deux Rives : contrôle de Felix le 12.11.2026 à 14:30.'));
        $this->assertSame('Neuchatel, ile, Noel, facon, n\'a pas', TexteSms::versGsm('Neuchâtel, île, Noël, façon, n’a pas'));
        // Les accents de l'alphabet SMS restent : é, è, à, ù, ü, ö, ä
        $this->assertSame('Félix, très, là, où, Zürich, Göteborg', TexteSms::versGsm('Félix, très, là, où, Zürich, Göteborg'));
    }

    public function test_les_segments_d_un_sms(): void
    {
        $this->assertSame(1, TexteSms::segments(str_repeat('a', 160)));
        $this->assertSame(2, TexteSms::segments(str_repeat('a', 161)));
        $this->assertSame(3, TexteSms::segments(str_repeat('a', 307)));
        // Hors alphabet SMS (UCS-2) : 70, puis 67 par segment
        $this->assertSame(1, TexteSms::segments(str_repeat('ô', 70)));
        $this->assertSame(2, TexteSms::segments(str_repeat('ô', 71)));
        // Les caractères de l'extension (€, [, ]) comptent double
        $this->assertSame(2, TexteSms::segments(str_repeat('a', 159).'€'));
    }
}
