<?php

namespace App\Services\Videos;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoPurchase;
use App\Paiements\PasserellePaiement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * L'achat d'une vidéo (31.10.2026) : payé en ligne sur le compte du
 * vendeur, Veterinary prélève 30 %. Définitif, sans remboursement, après
 * que l'acheteur a accepté que l'accès commence tout de suite.
 */
class VideoPurchases
{
    public function __construct(private PasserellePaiement $gateway, private Sellers $sellers) {}

    /** La commission de Veterinary sur une vente (centimes). */
    public static function commission(int $cents): int
    {
        return (int) round($cents * (float) config('services.stripe.commission_videos') / 100);
    }

    /**
     * Ouvre le paiement : le navigateur paie sur le compte du vendeur.
     *
     * @return array{client_secret: string, publishable_key: ?string, account: string, amount_cents: int}
     */
    public function open(Video $video, User $user): array
    {
        if ($video->status !== VideoStatus::Published || ! $video->isForAudienceOf($user) || $video->price_cents === null) {
            abort(404);
        }
        if ($video->seller_user_id === $user->id) {
            throw ValidationException::withMessages(['achat' => 'C’est votre vidéo : vous la regardez déjà en entier.']);
        }
        if ($video->isOwnedBy($user)) {
            throw ValidationException::withMessages(['achat' => 'Vous avez déjà acheté cette vidéo.']);
        }
        $account = $this->sellers->stripeAccount($video);
        $amount = (int) $video->price_cents;
        $commission = self::commission($amount);

        $purchase = DB::transaction(function () use ($video, $user, $account, $amount, $commission): VideoPurchase {
            $purchase = VideoPurchase::query()->where('video_id', $video->id)->where('user_id', $user->id)->lockForUpdate()->first();
            $purchase ??= new VideoPurchase(['video_id' => $video->id, 'user_id' => $user->id]);
            // Un prix ou un compte changé depuis : une nouvelle intention
            if ($purchase->amount_cents !== $amount || $purchase->stripe_account_id !== $account) {
                $purchase->payment_intent_id = null;
            }
            $purchase->fill(['amount_cents' => $amount, 'commission_cents' => $commission, 'stripe_account_id' => $account, 'terms_accepted_at' => now()])->save();

            return $purchase;
        });

        $intent = $this->gateway->pourCompte($account)->creerIntentionEnLigne($amount, [
            'objet' => 'video',
            'video_purchase_id' => (string) $purchase->id,
            'video' => $video->uuid,
        ], 'video-purchase-'.$purchase->id.'-'.$amount.'-'.$account, $commission);
        $purchase->forceFill(['payment_intent_id' => $intent['id']])->save();

        return [
            'client_secret' => $intent['client_secret'],
            'publishable_key' => config('services.stripe.key'),
            'account' => $account,
            'amount_cents' => $amount,
        ];
    }

    /** Le paiement vérifié auprès du prestataire : payé, la vidéo est à lui, à vie. Sans effet sinon. */
    public function confirm(VideoPurchase $purchase): VideoPurchase
    {
        return DB::transaction(function () use ($purchase): VideoPurchase {
            $purchase = VideoPurchase::query()->lockForUpdate()->findOrFail($purchase->id);
            if ($purchase->paid_at !== null || $purchase->payment_intent_id === null) {
                return $purchase;
            }
            if ($this->gateway->pourCompte($purchase->stripe_account_id)->lireIntention($purchase->payment_intent_id)['statut'] === 'succeeded') {
                $purchase->forceFill(['paid_at' => now()])->save();
            }

            return $purchase;
        });
    }

    /** Webhook `payment_intent.succeeded` d'un achat de vidéo, seulement depuis le compte qui a encaissé. */
    public function onPaymentSucceeded(string $intentId, ?string $account): void
    {
        $purchase = VideoPurchase::query()->where('payment_intent_id', $intentId)->first();
        if ($purchase === null || ($account !== null && $purchase->stripe_account_id !== $account)) {
            return;
        }
        $this->confirm($purchase);
    }
}
