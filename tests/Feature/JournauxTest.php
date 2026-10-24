<?php

namespace Tests\Feature;

use App\Models\Organisation;
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

    /** 1292 et les valeurs en fin de message : masquées aussi (24.10.2026). */
    public function test_les_autres_messages_mysql_avec_une_valeur_sont_masques(): void
    {
        foreach ([
            "SQLSTATE[22007]: Invalid datetime format: 1292 Truncated incorrect DOUBLE value: 'lea.fontaine@exemple.ch' (Connection: mysql, SQL: select * from `users` where `id` = ?)",
            "SQLSTATE[22007]: Invalid datetime format: 1292 Incorrect datetime value: 'lea.fontaine@exemple.ch' for column 'debut' at row 1",
            "1292 Truncated incorrect INTEGER value: 'lea.fontaine@exemple.ch'",
            "1366 Incorrect string value: 'lea.fontaine@exemple.ch' for column `veterinary`.`users`.`name` at row 1",
        ] as $message) {
            $masque = MasquageSql::message($message);
            $this->assertStringNotContainsString('lea.fontaine', $masque, $message);
            $this->assertStringContainsString("value: '***'", $masque, $message);
        }
    }

    /** Une erreur SQL non rattrapée passe par le masque, et seulement par lui. */
    public function test_le_rapporteur_des_erreurs_sql_est_branche(): void
    {
        Log::spy();
        report(new QueryException('mysql', 'insert into `users` (`email`) values (?)', [], new PDOException("SQLSTATE[23000]: 1062 Duplicate entry 'lea.fontaine@exemple.ch' for key 'users_email_unique'")));

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $journal): bool => str_contains($journal, "Duplicate entry '***'"));
        foreach (['error', 'critical', 'alert', 'emergency', 'log'] as $niveau) {
            Log::shouldNotHaveReceived($niveau, [\Mockery::on(fn ($m): bool => is_string($m) && str_contains($m, 'lea.fontaine')), \Mockery::any()]);
        }
    }

    /** Une tâche en échec est gardée sans la valeur du message ni les arguments de la pile. */
    public function test_une_tache_en_echec_est_gardee_sans_les_valeurs(): void
    {
        $echec = new QueryException('mysql', 'insert into `users` (`email`) values (?)', [], new PDOException("SQLSTATE[23000]: 1062 Duplicate entry 'lea.fontaine@exemple.ch' for key 'users_email_unique'"));
        $echec = (fn (string $email) => new RuntimeException('Enveloppe', 0, $echec))('lea.fontaine@exemple.ch');

        app('queue.failer')->log('database', 'default', '{"uuid":"0193a1b2-0000-7000-8000-000000000001","displayName":"Essai"}', $echec);

        $garde = (string) DB::table('failed_jobs')->value('exception');
        $this->assertStringContainsString("Duplicate entry '***' for key 'users_email_unique'", $garde);
        $this->assertStringNotContainsString('lea.fontaine', $garde);
        $this->assertStringContainsString(QueryException::class, $garde);
    }

    /** Le renouvellement d'un abonnement qui échoue au SQL : journalisé sans la valeur. */
    public function test_un_renouvellement_en_echec_est_journalise_sans_les_valeurs(): void
    {
        $organisation = Organisation::factory()->create(['statut_abonnement' => 'actif', 'periode_fin' => now()->subDay()]);
        Organisation::saving(fn () => throw new QueryException('mysql', 'update `organisations` set `x` = ?', [], new PDOException("SQLSTATE[23000]: 1062 Duplicate entry 'lea.fontaine@exemple.ch' for key 'x'")));
        Log::spy();

        $this->artisan('abonnements:renouveler')->assertSuccessful();

        Log::shouldHaveReceived('error')->withArgs(fn (string $journal, array $contexte): bool => $journal === 'Renouvellement impossible.'
            && $contexte['abonne'] === Organisation::class.':'.$organisation->id
            && str_contains($contexte['erreur'], "Duplicate entry '***'")
            && ! str_contains($contexte['erreur'], 'lea.fontaine'));
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
