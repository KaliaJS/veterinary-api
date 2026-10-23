<?php

namespace Tests\Feature;

use App\Models\Clinique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les adresses écrites par l'API (photos, logos, liens des e-mails)
 * partent d'APP_URL, jamais de l'en-tête Host envoyé (23.10.2026).
 */
class AdressesTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_en_tete_host_pirate_ne_change_pas_les_adresses(): void
    {
        $clinique = Clinique::factory()->dansLAnnuaire()->create();

        $photo = $this->withHeaders(['Host' => 'pirate.example'])
            ->getJson('https://pirate.example/api/v1/public/cliniques?cliniques='.$clinique->id)
            ->assertOk()
            ->json('data.0.photo.url');

        $this->assertStringStartsWith(rtrim((string) config('app.url'), '/').'/', $photo);
        $this->assertStringNotContainsString('pirate.example', $photo);
    }
}
