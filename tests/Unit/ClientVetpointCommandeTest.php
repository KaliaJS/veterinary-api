<?php

namespace Tests\Unit;

use App\Services\Vetpoint\ClientVetpoint;
use App\Services\Vetpoint\VetpointIndisponible;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Vetpoint, étape 3 (28.10.2026) : les formes possibles du numéro de commande et des péremptions. */
class ClientVetpointCommandeTest extends TestCase
{
    public function test_le_numero_de_commande_se_lit_en_texte_en_chaine_json_ou_dans_un_objet(): void
    {
        config(['services.vetpoint.url' => 'https://vetpoint.test/api/tapsw']);
        $client = ClientVetpoint::pour('cle');

        // Une réponse par appel (refaire Http::fake ne remplace pas la précédente)
        $reponses = [['V-1', 'text/plain'], ['"V-2"', 'application/json'], ['{"orderId":"V-3"}', 'application/json'], ['', 'text/plain']];
        $appel = 0;
        Http::fake(function () use (&$appel, $reponses) {
            [$corps, $type] = $reponses[$appel++];

            return Http::response($corps, 200, ['Content-Type' => $type]);
        });

        foreach ([1, 2, 3] as $n) {
            $this->assertSame('V-'.$n, $client->commander(['orderItems' => []]));
        }
        $this->expectException(VetpointIndisponible::class);
        $client->commander(['orderItems' => []]);
    }

    public function test_la_peremption_devient_le_premier_jour_du_mois(): void
    {
        $this->assertSame('2027-05-01', ClientVetpoint::peremption('2027-05-31'));
        $this->assertSame('2027-05-01', ClientVetpoint::peremption('2027-05-31T00:00:00'));
        $this->assertSame('2027-05-01', ClientVetpoint::peremption('31.05.2027'));
        $this->assertSame('2027-05-01', ClientVetpoint::peremption('05.2027'));
        $this->assertSame('2027-05-01', ClientVetpoint::peremption('05/2027'));
        $this->assertNull(ClientVetpoint::peremption('n/a'));
        $this->assertNull(ClientVetpoint::peremption('2027-13-01'));
        $this->assertNull(ClientVetpoint::peremption(null));
    }
}
