<?php

namespace App\Services\Clients;

use App\Mail\OwnerSpaceInvitation;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Inviter un propriétaire dans son espace (décision de Jim, 03.11.2026 ;
 * refait après vérification). La création ne rattache rien et ne regarde
 * aucun compte : l'invitation attend sur la fiche (adresse, date, clinique,
 * jeton haché) et le lien part par e-mail. La personne la prend connectée
 * avec l'adresse invitée, prouvée par le code e-mail, dans les 30 jours et
 * une seule fois : alors seulement la fiche rejoint son espace.
 */
class OwnerSpaceInvitations
{
    public const VALID_DAYS = 30;

    /** Enregistre l'invitation et met le mail en file, envoyé après l'enregistrement. */
    public static function invite(Client $client, string $email, Clinique $clinic): void
    {
        $email = mb_strtolower(trim($email));
        $token = Str::random(48);

        $client->forceFill([
            'space_invitation_email' => $email,
            'space_invitation_sent_at' => now(),
            'space_invitation_token_hash' => hash('sha256', $token),
            'space_invitation_clinic_id' => $clinic->id,
        ])->save();

        Mail::to($email)->queue(new OwnerSpaceInvitation($clinic, $token));
    }

    /** Annule l'invitation en attente : son lien ne vaut plus rien. */
    public static function cancel(Client $client): void
    {
        $client->forgetSpaceInvitation();
        $client->save();
    }

    /**
     * L'invitation prise par le compte connecté. Rend la fiche rattachée et
     * le nom de la clinique qui a invité (lu avant : le rattachement efface
     * l'invitation).
     *
     * @return array{client: Client, clinic: string|null}
     */
    public static function accept(string $token, User $user): array
    {
        return DB::transaction(function () use ($token, $user): array {
            // La fiche trouvée sans verrou, puis verrouillée par sa clé et revérifiée :
            // le même ordre de verrous que l'enregistrement de la fiche (pas d'interblocage)
            $hash = hash('sha256', $token);
            $id = Client::withoutGlobalScopes()->where('space_invitation_token_hash', $hash)->value('id');
            $client = $id === null ? null : Client::withoutGlobalScopes()->whereKey($id)->lockForUpdate()->first();

            if ($client === null || $client->space_invitation_token_hash !== $hash || $client->space_invitation_email === null) {
                self::refuse('Ce lien n’est plus valable : demandez une nouvelle invitation à la clinique.');
            }
            if ($client->space_invitation_sent_at === null || $client->space_invitation_sent_at->lt(now()->subDays(self::VALID_DAYS))) {
                self::refuse('Ce lien a expiré : demandez une nouvelle invitation à la clinique.');
            }
            // L'adresse invitée doit être encore celle de la fiche (corrigée depuis : le lien tombe)
            if (mb_strtolower(trim((string) $client->email)) !== $client->space_invitation_email) {
                self::refuse('Ce lien n’est plus valable : demandez une nouvelle invitation à la clinique.');
            }
            if (mb_strtolower(trim((string) $user->email)) !== $client->space_invitation_email) {
                self::refuse('Connectez-vous avec l’adresse qui a reçu l’invitation.');
            }
            // L'adresse doit être prouvée (code e-mail), comme pour l'invitation dans une équipe
            if ($user->email_verified_at === null) {
                self::refuse('Confirmez d’abord votre adresse e-mail : connectez-vous avec le code reçu par e-mail, puis ouvrez de nouveau le lien.');
            }
            if ($client->user_id !== null && $client->user_id !== $user->id) {
                self::refuse('Cette fiche est déjà dans un autre espace : demandez à la clinique.');
            }

            $clinic = $client->space_invitation_clinic_id === null ? null : Clinique::withoutGlobalScopes()->whereKey($client->space_invitation_clinic_id)->value('nom');
            OwnerAccount::attach($client, $user);
            self::cancel($client);

            return ['client' => $client, 'clinic' => $clinic];
        });
    }

    private static function refuse(string $message): never
    {
        throw ValidationException::withMessages(['invitation' => $message]);
    }
}
