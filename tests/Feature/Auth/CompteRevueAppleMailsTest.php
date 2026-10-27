<?php

namespace Tests\Feature\Auth;

use App\Listeners\LogoDansLesMails;
use App\Mail\AccesSurInvitation;
use App\Notifications\CodeEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Aucun e-mail ne part vers l'adresse de revue d'Apple (27.10.2026) : pas
 * seulement le code de connexion, tous les e-mails (accusé d'une demande de
 * rendez-vous, notifications…), coupés au moment de l'envoi. Vrai envoi par
 * le transport « array » (pas de Mail::fake, qui court-circuite l'envoi).
 */
class CompteRevueAppleMailsTest extends TestCase
{
    use RefreshDatabase;

    private const string ADRESSE = 'revue@veterinary.app';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.default' => 'array',
            'plateforme.revue_apple.email' => self::ADRESSE,
            'plateforme.revue_apple.code' => '24681357',
        ]);
    }

    /** @return list<Email> */
    private function envoyes(): array
    {
        return array_map(
            fn ($envoi) => $envoi->getOriginalMessage(),
            iterator_to_array(Mail::mailer('array')->getSymfonyTransport()->messages()),
        );
    }

    /** @return list<string> */
    private function destinataires(Email $message): array
    {
        return array_map(
            fn (Address $adresse) => mb_strtolower($adresse->getAddress()),
            [...$message->getTo(), ...$message->getCc(), ...$message->getBcc()],
        );
    }

    public function test_un_e_mail_adresse_au_compte_de_revue_ne_part_pas(): void
    {
        Mail::to('Revue@Veterinary.app')->send(new AccesSurInvitation);
        Mail::to(self::ADRESSE)->queue(new AccesSurInvitation);
        Notification::route('mail', self::ADRESSE)->notify(new CodeEmailNotification('12345678', 10, 'https://veterinary.app'));
        // Adressé à elle seule : ses copies ne partent pas non plus
        Mail::to(self::ADRESSE)->bcc('plateforme@exemple.ch')->send(new AccesSurInvitation);

        $this->assertSame([], $this->envoyes());
    }

    public function test_en_copie_l_adresse_de_revue_est_retiree_et_le_reste_part(): void
    {
        Mail::to('paul@exemple.ch')->cc(self::ADRESSE)->bcc(['plateforme@exemple.ch', self::ADRESSE])->send(new AccesSurInvitation);

        $envoyes = $this->envoyes();
        $this->assertCount(1, $envoyes);
        $this->assertSame(['paul@exemple.ch', 'plateforme@exemple.ch'], $this->destinataires($envoyes[0]));
    }

    /** Un e-mail à plusieurs (alerte aux admins d'une clinique) part aux autres, sans l'adresse de revue. */
    public function test_a_plusieurs_destinataires_l_e_mail_part_aux_autres(): void
    {
        Mail::to(['paul@exemple.ch', ['email' => 'REVUE@veterinary.app', 'name' => 'Revue'], 'lea@exemple.ch'])->send(new AccesSurInvitation);

        $envoyes = $this->envoyes();
        $this->assertCount(1, $envoyes);
        $this->assertSame(['paul@exemple.ch', 'lea@exemple.ch'], $this->destinataires($envoyes[0]));
    }

    public function test_les_autres_adresses_recoivent_leurs_e_mails(): void
    {
        Mail::to('paul@exemple.ch')->send(new AccesSurInvitation);

        $envoyes = $this->envoyes();
        $this->assertCount(1, $envoyes);
        // Le garde laisse passer les autres listeners : le logo reste joint
        $logo = array_filter($envoyes[0]->getAttachments(), fn ($partie) => $partie->getContentId() === LogoDansLesMails::CID);
        $this->assertCount(1, $logo);
    }

    /** Sans destinataire principal, l'adresse de revue seule en copie : rien ne part (et rien ne plante). */
    public function test_sans_destinataire_principal_l_adresse_de_revue_seule_en_copie_ne_recoit_rien(): void
    {
        Mail::bcc(self::ADRESSE)->send(new AccesSurInvitation);
        Mail::cc([['email' => 'Revue@Veterinary.app', 'name' => 'Revue']])->send(new AccesSurInvitation);

        $this->assertSame([], $this->envoyes());
    }

    /** Sans réglage valable, l'adresse de revue est une adresse comme les autres. */
    public function test_sans_reglage_valable_l_adresse_de_revue_recoit_ses_e_mails(): void
    {
        foreach ([null, '1234', '123456789'] as $code) {
            config(['plateforme.revue_apple.code' => $code]);

            Mail::to(self::ADRESSE)->send(new AccesSurInvitation);
        }

        $this->assertCount(3, $this->envoyes());
    }
}
