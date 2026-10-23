<?php

namespace Tests\Feature\Planning;

use App\Mail\DemandeRendezVousEnvoyee;
use App\Models\DemandeRendezVous;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les e-mails reprennent des saisies publiques (nom de l'animal, message) :
 * elles restent du texte, jamais un lien ni du HTML (23.10.2026, un
 * inconnu pouvait faire envoyer un faux « Rendez-vous confirmé » avec un
 * lien piégé au nom d'une clinique).
 */
class EmailsDemandesTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_lien_markdown_dans_le_nom_de_l_animal_reste_du_texte(): void
    {
        $demande = DemandeRendezVous::factory()->create([
            'patient_name' => "[Payer l'acompte](https://hameconnage.example)",
        ]);

        $html = (new DemandeRendezVousEnvoyee($demande))->render();

        $this->assertStringNotContainsString('href="https://hameconnage.example"', $html);
        $this->assertStringContainsString('Payer l', $html);
    }

    public function test_du_html_dans_le_nom_de_l_animal_reste_du_texte(): void
    {
        $demande = DemandeRendezVous::factory()->create([
            'patient_name' => '<a href="https://hameconnage.example">Rex</a>',
        ]);

        $html = (new DemandeRendezVousEnvoyee($demande))->render();

        $this->assertStringNotContainsString('<a href="https://hameconnage.example"', $html);
    }
}
