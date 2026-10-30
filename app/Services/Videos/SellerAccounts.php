<?php

namespace App\Services\Videos;

use App\Models\User;
use App\Paiements\PasserellePaiement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Le compte de paiement d'un vétérinaire qui vend ses vidéos en son nom
 * (31.10.2026) : comme celui d'une société, sans tableau de bord, ouvert
 * et géré depuis Veterinary par les composants intégrés du prestataire.
 */
class SellerAccounts
{
    /** Composants intégrés proposés au vendeur : l'inscription, puis la gestion et les virements. */
    public const COMPONENTS = ['account_onboarding', 'account_management', 'notification_banner', 'payouts', 'balances'];

    /** Services d'enseignement : ce que vend un vétérinaire en son nom. */
    private const MCC = '8299';

    public function __construct(private PasserellePaiement $gateway) {}

    /** Ouvre son compte s'il n'en a pas, puis relit son état. */
    public function open(User $user): User
    {
        if (! $user->estVerifie()) {
            throw ValidationException::withMessages(['compte' => 'Vendre une vidéo demande un compte de vétérinaire vérifié : vérifiez votre identité dans Réglages, Profil.']);
        }

        DB::transaction(function () use ($user): void {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($locked->stripe_account_id === null) {
                $id = $this->gateway->pourCompte(null)->creerComptePaiement([
                    'type' => 'individual',
                    'nom' => $locked->name,
                    'nom_commercial' => $locked->name,
                    'email' => $locked->email,
                    'mcc' => self::MCC,
                    'metadonnees' => ['user_id' => (string) $locked->id, 'objet' => 'vendeur_videos'],
                ]);
                $locked->forceFill(['stripe_account_id' => $id])->save();
            }
        });

        return $this->refresh($user->refresh());
    }

    /** Relit l'état du compte : peut-il encaisser. */
    public function refresh(User $user): User
    {
        if ($user->stripe_account_id === null) {
            return $user;
        }
        $state = $this->gateway->pourCompte(null)->lireComptePaiement($user->stripe_account_id);
        $user->forceFill(['stripe_account_ready_at' => $state['paiements'] ? ($user->stripe_account_ready_at ?? now()) : null])->save();

        return $user;
    }

    /** Le secret de session des composants intégrés. */
    public function session(User $user): string
    {
        if ($user->stripe_account_id === null) {
            throw ValidationException::withMessages(['compte' => 'Le compte de paiement n’est pas encore ouvert.']);
        }

        return $this->gateway->pourCompte(null)->creerSessionCompte($user->stripe_account_id, self::COMPONENTS);
    }
}
