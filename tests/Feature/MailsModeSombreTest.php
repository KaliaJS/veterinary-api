<?php

namespace Tests\Feature;

use App\Mail\DemandeRendezVousEnvoyee;
use App\Models\DemandeRendezVous;
use App\Models\User;
use App\Notifications\CodeEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mail de macOS en mode sombre (27.10.2026, vu par Jim) : il assombrit
 * un fond blanc pur sans éclaircir le texte posé dessus (bouton illisible)
 * et ignore l'attribut width d'une image intégrée (wordmark à 668 px,
 * étiré sur toute la largeur). Le code de connexion et les autres
 * e-mails (gabarit Markdown) ont le même bouton et le même wordmark.
 */
class MailsModeSombreTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function rendus(): array
    {
        $code = new CodeEmailNotification('12345678', 10, 'https://veterinary.app/?connexion=1#email=a%40b.ch&code=12345678');

        return [
            'code de connexion' => (string) $code->toMail(new User(['email' => 'a@b.ch']))->render(),
            'gabarit Markdown' => (new DemandeRendezVousEnvoyee(DemandeRendezVous::factory()->create()))->render(),
        ];
    }

    public function test_aucun_bouton_n_a_un_fond_blanc_pur(): void
    {
        foreach ($this->rendus() as $mail => $html) {
            $this->assertMatchesRegularExpression('/class="bouton"/', $html, $mail);
            $this->assertDoesNotMatchRegularExpression('/bgcolor="#fff(fff)?"|background-color:\s*#fff(fff)?\b|background-color:\s*white\b/i', $html, $mail);
        }
    }

    public function test_le_wordmark_a_sa_largeur_dans_le_style(): void
    {
        foreach ($this->rendus() as $mail => $html) {
            $this->assertSame(1, preg_match('/<img[^>]*alt="veterinary"[^>]*>/', $html, $image), $mail);
            $this->assertMatchesRegularExpression('/style="[^"]*\bwidth:\s*168px/', $image[0], $mail);
            $this->assertMatchesRegularExpression('/style="[^"]*\bheight:\s*43px/', $image[0], $mail);
        }
    }
}
