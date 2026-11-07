<?php

namespace Tests\Feature\Messages;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Les migrations des messages audio et vidéo se défont et se refont sans
 * erreur, sous SQLite comme sous MySQL (vérification du 07.11.2026 : le
 * retour en arrière des fils internes laissait un index unique sur une
 * colonne effacée).
 */
class MessageMediaMigrationsTest extends TestCase
{
    use DatabaseMigrations;

    private const array MIGRATIONS = [
        'database/migrations/2026_11_07_000500_message_media_provider_at_index.php',
        'database/migrations/2026_11_07_000400_message_media_provider_seconds.php',
        'database/migrations/2026_11_07_000300_message_media_erased_with_account.php',
        'database/migrations/2026_11_07_000200_message_media_internal_threads.php',
        'database/migrations/2026_11_07_000100_create_message_media_table.php',
    ];

    public function test_migrer_revenir_en_arriere_et_remigrer_sans_erreur(): void
    {
        foreach (self::MIGRATIONS as $migration) {
            $this->artisan('migrate:rollback', ['--path' => $migration, '--force' => true])->assertSuccessful();
        }
        $this->assertFalse(Schema::hasTable('message_media'));

        foreach (array_reverse(self::MIGRATIONS) as $migration) {
            $this->artisan('migrate', ['--path' => $migration, '--force' => true])->assertSuccessful();
        }
        $this->assertTrue(Schema::hasColumns('message_media', ['staff_message_id', 'clinic_message_id', 'erased_at', 'provider_seconds']));
        // Le budget de la plateforme se lit par mois sur provider_at (troisième vérification)
        $this->assertTrue(Schema::hasIndex('message_media', ['provider_at']));
    }

    public function test_les_fils_internes_se_defont_seuls(): void
    {
        foreach ([0, 1, 2, 3] as $i) {
            $this->artisan('migrate:rollback', ['--path' => self::MIGRATIONS[$i], '--force' => true])->assertSuccessful();
        }

        $this->assertTrue(Schema::hasTable('message_media'));
        $this->assertFalse(Schema::hasColumn('message_media', 'staff_message_id'));
        $this->assertFalse(Schema::hasColumn('message_media', 'clinic_message_id'));

        // Et elles se refont (la dernière vague, défaite à la fin du test, est la leur)
        foreach ([3, 2, 1, 0] as $i) {
            $this->artisan('migrate', ['--path' => self::MIGRATIONS[$i], '--force' => true])->assertSuccessful();
        }
        $this->assertTrue(Schema::hasColumns('message_media', ['staff_message_id', 'clinic_message_id', 'erased_at', 'provider_seconds']));
    }
}
