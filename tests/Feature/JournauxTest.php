<?php

namespace Tests\Feature;

use App\Services\Transcription\TranscripteurDeepgram;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Ce qui part au journal avec une erreur (23.10.2026) : jamais les
 * valeurs d'une requête SQL, jamais l'adresse appelée chez Deepgram (elle
 * porte les mots rares : races, médicaments).
 */
class JournauxTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_erreur_sql_ne_porte_pas_les_valeurs(): void
    {
        try {
            DB::table('users')->where('email', 'lea.fontaine@exemple.ch')->update(['colonne_inexistante' => 'Bouledogue']);
            $this->fail('La requête aurait dû échouer.');
        } catch (QueryException $e) {
            $this->assertStringNotContainsString('lea.fontaine@exemple.ch', $e->getMessage());
            $this->assertStringNotContainsString('Bouledogue', $e->getMessage());
        }
    }

    public function test_deepgram_injoignable_sans_l_adresse_ni_les_mots_rares(): void
    {
        Http::fake(['api.deepgram.com/*' => fn () => throw new ConnectionException('cURL error 28: timeout for https://api.deepgram.com/v1/listen?keyterm=Bouledogue&keyterm=Kétamine')]);
        $fichier = tempnam(sys_get_temp_dir(), 'audio');
        file_put_contents($fichier, 'audio');

        try {
            app(TranscripteurDeepgram::class)->transcrire($fichier, 'audio/webm', ['Bouledogue', 'Kétamine']);
            $this->fail('Deepgram injoignable aurait dû échouer.');
        } catch (RuntimeException $e) {
            $this->assertSame('Deepgram injoignable.', $e->getMessage());
        } finally {
            unlink($fichier);
        }
    }
}
