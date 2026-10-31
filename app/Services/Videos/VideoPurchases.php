<?php

namespace App\Services\Videos;

use App\Enums\VideoFilesStatus;
use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoPurchase;
use App\Paiements\PasserellePaiement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

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
        // Un épisode ne s'achète jamais seul : on achète sa série (décision de Jim)
        abort_if($video->isEpisode(), 404);
        // Ses fichiers refusés à la relecture au moment d'une mise en vente : elle ne s'achète pas ;
        // une série, sans fichiers, s'achète tant qu'elle a au moins un épisode ajouté
        $isReady = $video->isSeries()
            ? $video->episodes()->where('status', VideoStatus::Published->value)->exists()
            : $video->files_status === VideoFilesStatus::Ready;
        if ($video->status !== VideoStatus::Published || ! $isReady || ! $video->isForAudienceOf($user) || $video->price_cents === null) {
            abort(404);
        }
        if ($video->price_cents === 0) {
            throw ValidationException::withMessages(['purchase' => $video->isSeries() ? 'Cette série est gratuite : regardez-la en entier, sans l’acheter.' : 'Cette vidéo est gratuite : regardez-la en entier, sans l’acheter.']);
        }
        if ($video->isOnSellerSide($user)) {
            throw ValidationException::withMessages(['purchase' => 'C’est votre vidéo : vous la regardez déjà en entier.']);
        }
        if ($video->isOwnedBy($user)) {
            throw ValidationException::withMessages(['purchase' => $video->isSeries() ? 'Vous avez déjà acheté cette série.' : 'Vous avez déjà acheté cette vidéo.']);
        }
        // Un vendeur qui n'est plus vérifié, ou une organisation fermée, ne vend plus
        $this->sellers->assertStillSelling($video);
        $account = $this->sellers->stripeAccount($video);
        $amount = (int) $video->price_cents;
        $commission = self::commission($amount);

        $purchase = DB::transaction(function () use ($video, $user, $amount, $commission, $account): VideoPurchase {
            $purchase = VideoPurchase::query()->where('video_id', $video->id)->where('user_id', $user->id)->lockForUpdate()->first();

            return $purchase ?? VideoPurchase::query()->create([
                'video_id' => $video->id, 'user_id' => $user->id, 'amount_cents' => $amount,
                'commission_cents' => $commission, 'stripe_account_id' => $account, 'terms_accepted_at' => now(),
            ]);
        });

        // Une intention d'avant (autre onglet, prix changé, plus de 24 heures) : payée,
        // la vidéo est à lui ; sinon on l'annule, pour qu'elle ne se paie plus sans rien donner
        $previous = $purchase->payment_intent_id;
        $previousAccount = $purchase->stripe_account_id;
        if ($previous !== null && $this->settle($purchase, $previous)) {
            throw ValidationException::withMessages(['purchase' => 'Vous avez déjà acheté cette vidéo.']);
        }

        $intent = $this->gateway->pourCompte($account)->creerIntentionEnLigne($amount, [
            'objet' => 'video',
            'video_purchase_id' => (string) $purchase->id,
            'video' => $video->uuid,
        ], 'video-purchase-'.$purchase->id.'-'.$amount.'-'.$account.'-'.now()->format('Ymd').'-'.($previous ?? 'first'), $commission);
        if ($previous !== null && $previous !== $intent['id']) {
            try {
                $this->gateway->pourCompte($previousAccount)->annulerIntention($previous);
            } catch (Throwable $e) {
                report($e);
            }
        }
        $purchase->forceFill([
            'amount_cents' => $amount, 'commission_cents' => $commission, 'stripe_account_id' => $account,
            'payment_intent_id' => $intent['id'], 'terms_accepted_at' => now(),
        ])->save();

        return [
            'client_secret' => $intent['client_secret'],
            'publishable_key' => config('services.stripe.key'),
            'account' => $account,
            'amount_cents' => $amount,
        ];
    }

    /** Le paiement vérifié auprès du prestataire : payé, la vidéo est à lui, à vie. Sans effet sinon. */
    /**
     * Au retour d'un paiement qui a demandé une étape de plus (TWINT,
     * 3-D Secure) : payé, en cours, ou n'a pas abouti (annulé, refusé : rien
     * n'a été débité, huitième vérification).
     *
     * @return array{paid: bool, failed: bool}
     */
    public function confirm(VideoPurchase $purchase): array
    {
        $intent = $purchase->payment_intent_id;
        if ($intent === null) {
            return ['paid' => false, 'failed' => false];
        }
        if ($this->settle($purchase, $intent)) {
            return ['paid' => true, 'failed' => false];
        }
        try {
            $state = $this->gateway->pourCompte($purchase->stripe_account_id)->lireIntention($intent);
        } catch (Throwable $e) {
            report($e);

            return ['paid' => false, 'failed' => false];
        }

        return ['paid' => false, 'failed' => in_array($state['statut'], ['requires_payment_method', 'canceled'], true)];
    }

    /**
     * Une intention de cet achat, relue chez le prestataire sur le compte qui
     * l'a reçue : payée, l'achat l'est aussi.
     */
    private function settle(VideoPurchase $purchase, string $intentId, ?string $account = null): bool
    {
        return DB::transaction(function () use ($purchase, $intentId, $account): bool {
            $locked = VideoPurchase::query()->lockForUpdate()->findOrFail($purchase->id);
            if ($locked->paid_at !== null) {
                return true;
            }
            $account ??= $locked->stripe_account_id;
            try {
                $state = $this->gateway->pourCompte($account)->lireIntention($intentId);
            } catch (Throwable $e) {
                report($e);

                return false;
            }
            if ($state['statut'] !== 'succeeded') {
                return false;
            }
            $locked->forceFill(['paid_at' => now(), 'payment_intent_id' => $intentId, 'stripe_account_id' => $account])->save();

            return true;
        });
    }

    /**
     * Webhook `payment_intent.succeeded` d'un achat de vidéo, seulement depuis
     * le compte qui a encaissé ; aussi pour une intention remplacée depuis
     * (payée dans un vieil onglet), retrouvée par son achat.
     */
    public function onPaymentSucceeded(string $intentId, ?string $account, ?string $purchaseId = null): void
    {
        $purchase = VideoPurchase::query()->where('payment_intent_id', $intentId)->first()
            ?? ($purchaseId !== null && $account !== null ? VideoPurchase::query()->find((int) $purchaseId) : null);
        if ($purchase === null) {
            return;
        }
        if ($account !== null && $purchase->payment_intent_id === $intentId && $purchase->stripe_account_id !== $account) {
            return;
        }
        // Relue chez le prestataire, sur le compte d'où vient l'événement : un faux ne passe pas
        $this->settle($purchase, $intentId, $account ?? $purchase->stripe_account_id);
    }
}
