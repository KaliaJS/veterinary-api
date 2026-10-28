<?php

namespace Tests\Feature\Organisation;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\Document;
use App\Models\Organisation;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Stockage de l'organisation (décision de Jim, 28.10.2026 : « il faut pas
 * compter que les radios mais tous les autres DICOM, et autres documents
 * si on en a ») : les DICOM en direct, les photos (animaux, équipe,
 * cliniques), le logo du site et les consentements signés comptés chaque
 * nuit à leur taille réelle. L'audio des consultations, effacé en quelques
 * jours, ne compte pas.
 */
class StockageOrganisationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_photos_logo_et_consentements_comptent_dans_le_stockage(): void
    {
        Storage::fake('prive');
        $clinique = Clinique::factory()->create();
        $org = $clinique->societe->organisation_id;
        ['jeton' => $admin] = $this->administrateurAvecJeton($clinique);
        $patient = Patient::factory()->for(Client::factory()->create(['organisation_id' => $org]))->create();
        $disque = Storage::disk('prive');
        $disque->put("photos-animaux/{$org}/a.webp", str_repeat('a', 1000));
        $disque->put("photos/{$org}/b.webp", str_repeat('b', 2000));
        $disque->put("photos-cliniques/{$org}/c-400.webp", str_repeat('c', 3000));
        $disque->put("logos/{$org}/d.webp", str_repeat('d', 4000));
        $disque->put('consentements/7-x.png', str_repeat('e', 5000));
        Document::factory()->create(['patient_id' => $patient->id, 'chemin' => 'consentements/7-x.png']);
        // Une autre organisation : ses fichiers ne comptent pas ici
        $disque->put('photos-animaux/'.($org + 1000).'/z.webp', str_repeat('z', 9999));
        // L'audio des consultations, effacé en quelques jours : ne compte pas
        $disque->put('transcriptions/1/1/0001.webm', str_repeat('t', 7777));

        $this->artisan('stockage:compter')->assertSuccessful();

        $this->assertSame(15000, Organisation::query()->findOrFail($org)->stockageOctets());
        $this->getJson('/api/v1/organisation', $this->enTetes($admin))->assertOk()->assertJsonPath('data.usage.stockage_octets', 15000);
    }

    public function test_le_comptage_de_la_nuit_est_planifie(): void
    {
        $this->artisan('schedule:list')->assertSuccessful()->expectsOutputToContain('stockage:compter');
    }
}
