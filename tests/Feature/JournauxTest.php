<?php

namespace Tests\Feature;

use App\Services\Transcription\TranscripteurDeepgram;
use App\Support\MasquageSql;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PDOException;
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

    /** MySQL répète la valeur fautive dans son message : elle ne va jamais au journal. */
    public function test_le_message_du_pilote_mysql_est_masque_au_journal(): void
    {
        $message = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'lea.fontaine@exemple.ch' for key 'users_email_unique' (Connection: mysql, SQL: insert into `users` (`email`) values (?))";
        $masque = MasquageSql::message($message);
        $this->assertStringNotContainsString('lea.fontaine@exemple.ch', $masque);
        $this->assertStringContainsString("Duplicate entry '***' for key 'users_email_unique'", $masque);
        $this->assertStringNotContainsString('7601000999993', MasquageSql::message("1366 Incorrect integer value: '7601000999993' for column 'gln' at row 1"));

        Log::spy();
        MasquageSql::journaliser(new QueryException('mysql', 'insert into `users` (`email`) values (?)', ['lea.fontaine@exemple.ch'], new PDOException($message), maskBindings: true));
        Log::shouldHaveReceived('error')->withArgs(fn (string $journal, array $contexte): bool => ! str_contains($journal.json_encode($contexte), 'lea.fontaine@exemple.ch'));
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
