<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\CodeEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * E-mail du code : un lien de connexion directe pour la connexion et
 * l'inscription (adresse et code dans le fragment du front), aucun pour
 * la vérification d'adresse d'un compte déjà connecté ; le gabarit
 * montre le code en deux groupes sans espace copiable.
 */
class CodeEmailMessageTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_l_email_de_connexion_porte_un_lien_vers_le_front(): void
    {
        Notification::fake();
        config(['app.frontend_url' => 'https://app.veterinary.local:5174/']);

        $this->postJson('/api/v1/auth/code-email/envoyer', ['email' => 'nouvelle+test@exemple.ch'])->assertStatus(202);

        Notification::assertSentOnDemand(CodeEmailNotification::class, function (CodeEmailNotification $notification): bool {
            $this->assertSame(
                'https://app.veterinary.local:5174/?connexion=1#email=nouvelle%2Btest%40exemple.ch&code='.$notification->code,
                $notification->lien,
            );

            $html = (string) $notification->toMail(new User(['email' => 'nouvelle+test@exemple.ch']))->render();
            $this->assertStringContainsString('Me connecter', $html);
            $this->assertStringContainsString(substr($notification->code, 0, 4).'<span', $html);

            return true;
        });
    }

    public function test_la_verification_d_adresse_n_envoie_pas_de_lien(): void
    {
        Notification::fake();
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['email_verified_at' => null]);

        $this->postJson('/api/v1/espace/email/envoyer', [], $this->enTetes($jeton))->assertStatus(202);

        Notification::assertSentTo($user, CodeEmailNotification::class, function (CodeEmailNotification $notification): bool {
            $this->assertNull($notification->lien);

            return true;
        });
    }
}
