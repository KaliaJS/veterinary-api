<?php

namespace Tests\Concerns;

use App\Mail\CodeDemandeRendezVous;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Testing\Fakes\MailFake;

/**
 * Demande sans compte (23.10.2026) : le captcha passe, le code part à
 * l'adresse, on le lit dans l'e-mail envoyé.
 */
trait DeposeSansCompte
{
    protected function codeDeDemande(int $cliniqueId, string $email): string
    {
        if (! Mail::getFacadeRoot() instanceof MailFake) {
            Mail::fake();
        }
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->postJson('/api/v1/public/demandes/code', ['clinique_id' => $cliniqueId, 'email' => $email, 'captcha' => 'jeton-turnstile'])
            ->assertStatus(202);

        $code = null;
        Mail::assertSent(CodeDemandeRendezVous::class, function (CodeDemandeRendezVous $mail) use (&$code, $email): bool {
            if ($mail->hasTo(mb_strtolower($email))) {
                $code = $mail->code;
            }

            return true;
        });

        return (string) $code;
    }
}
