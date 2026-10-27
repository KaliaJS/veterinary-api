<?php

namespace App\Console\Commands;

use App\Mail\InvitationAccesEnvoyee;
use App\Models\InvitationAcces;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Accès sur invitation (27.10.2026) : invite une adresse sans passer par
 * l'écran Plateforme, pour le premier compte en production
 * (`php artisan plateforme:inviter a@b.ch`, --prevenir pour l'e-mail).
 */
class InviterPlateforme extends Command
{
    protected $signature = 'plateforme:inviter {email} {--note= : Note visible dans la liste} {--prevenir : Envoie l’e-mail d’invitation}';

    protected $description = 'Invite une adresse à créer son compte tant que l’accès est sur invitation';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Adresse e-mail invalide.');

            return self::FAILURE;
        }

        if (InvitationAcces::query()->where('email', $email)->exists()) {
            $this->info('Cette adresse a déjà une invitation : '.$email.'.');

            return self::SUCCESS;
        }

        $invitation = InvitationAcces::query()->create(['email' => $email, 'note' => $this->option('note')]);
        if ($this->option('prevenir')) {
            // Comme l'écran : un e-mail qui ne part pas ne laisse rien
            try {
                Mail::to($email)->send(new InvitationAccesEnvoyee);
            } catch (Throwable $e) {
                report($e);
                $invitation->delete();
                $this->error('L’e-mail n’a pas pu partir : rien n’est gardé. ('.$e->getMessage().')');

                return self::FAILURE;
            }
        }
        $this->info('Invitation ajoutée pour '.$email.($this->option('prevenir') ? ', e-mail envoyé.' : '.'));

        return self::SUCCESS;
    }
}
