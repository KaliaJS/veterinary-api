<?php

namespace App\Services\Abonnements;

use App\Enums\ArticlePlanete;
use App\Models\AchatPlanete;
use App\Models\User;
use App\Paiements\AppStore\JwsInvalide;
use App\Paiements\AppStore\VerificateurJws;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Achats dans l'app iPhone (17.10.2026, StoreKit 2) : Plus et les objets
 * de la planète s'y paient par l'App Store, comme Apple l'exige pour un
 * contenu numérique. Prix décidé par Jim : celui du site majoré de 30 %
 * (MAJORATION), fixé dans App Store Connect ; l'app affiche le prix que
 * lui donne l'App Store.
 *
 * L'app envoie chaque transaction signée (JWS) ; Apple prévient aussi le
 * serveur (notifications V2 : renouvellement, résiliation, remboursement).
 * Une transaction n'est acceptée que pour notre app et pour le compte qui
 * l'a passée (appAccountToken = users.apple_compte). Plus d'Apple vaut
 * jusqu'à la fin de la période achetée (users.apple_fin), à côté de
 * l'abonnement Stripe ; un objet acheté est posé comme un achat du site.
 */
class AchatsAppStore
{
    public const MAJORATION = 1.3;

    public function __construct(private VerificateurJws $jws) {}

    public static function produitPlus(): string
    {
        return config('services.apple.bundle_id').'.plus';
    }

    /** Identifiant App Store du compte (appAccountToken), créé au premier besoin. */
    public function compte(User $user): string
    {
        if (! $user->apple_compte) {
            $user->forceFill(['apple_compte' => (string) Str::uuid()])->save();
        }

        return $user->apple_compte;
    }

    /** Produits à demander à l'App Store, avec le prix de référence (site + 30 %). */
    public function produits(): array
    {
        return [
            'plus' => ['id' => self::produitPlus(), 'prix_reference' => $this->majore(AbonnementSaas::PLUS_CENTIMES)],
            'articles' => collect(ArticlePlanete::cases())->mapWithKeys(fn (ArticlePlanete $a): array => [
                $a->value => ['id' => $a->produitAppStore(), 'prix_reference' => $this->majore($a->centimes())],
            ])->all(),
        ];
    }

    /** Transaction envoyée par l'app du compte. */
    public function enregistrer(User $user, string $jws): void
    {
        $transaction = $this->transaction($jws);
        if (($transaction['appAccountToken'] ?? null) !== $this->compte($user)) {
            throw ValidationException::withMessages(['jws' => 'Cet achat appartient à un autre compte.']);
        }
        $this->appliquer($user, $transaction);
    }

    /** Notification serveur d'Apple (V2). */
    public function notification(string $signedPayload): void
    {
        $notification = $this->jws->verifier($signedPayload);
        $donnees = $notification['data'] ?? [];
        $this->verifierApp($donnees);
        if (empty($donnees['signedTransactionInfo'])) {
            return;
        }
        $transaction = $this->transaction((string) $donnees['signedTransactionInfo']);
        $renouvellement = empty($donnees['signedRenewalInfo']) ? null : $this->jws->verifier((string) $donnees['signedRenewalInfo']);

        $user = $this->proprietaire($transaction);
        if ($user) {
            $this->appliquer($user, $transaction, $renouvellement);
        }
    }

    /** @return array<string, mixed> */
    private function transaction(string $jws): array
    {
        try {
            $transaction = $this->jws->verifier($jws);
        } catch (JwsInvalide $e) {
            throw ValidationException::withMessages(['jws' => 'Achat App Store non reconnu.']);
        }
        $this->verifierApp($transaction);

        return $transaction;
    }

    private function verifierApp(array $donnees): void
    {
        if (($donnees['bundleId'] ?? null) !== config('services.apple.bundle_id')) {
            throw ValidationException::withMessages(['jws' => 'Achat d’une autre app.']);
        }
        $environnements = ['Production', 'Sandbox'];
        if (config('services.apple.accepte_xcode')) {
            $environnements[] = 'Xcode';
        }
        if (! in_array($donnees['environment'] ?? null, $environnements, true)) {
            throw ValidationException::withMessages(['jws' => 'Environnement App Store inattendu.']);
        }
    }

    private function proprietaire(array $transaction): ?User
    {
        $jeton = $transaction['appAccountToken'] ?? null;
        $original = (string) ($transaction['originalTransactionId'] ?? '');

        return ($jeton ? User::query()->where('apple_compte', $jeton)->first() : null)
            ?? User::query()->where('apple_abonnement_id', $original)->first()
            ?? AchatPlanete::query()->where('apple_transaction_id', $original)->first()?->user;
    }

    private function appliquer(User $user, array $transaction, ?array $renouvellement = null): void
    {
        $produit = (string) ($transaction['productId'] ?? '');
        $original = (string) ($transaction['originalTransactionId'] ?? $transaction['transactionId'] ?? '');
        $revoque = ! empty($transaction['revocationDate']);

        if ($produit === self::produitPlus()) {
            $fin = isset($transaction['expiresDate']) ? CarbonImmutable::createFromTimestampMs((int) $transaction['expiresDate']) : null;
            $memeAbonnement = $user->apple_abonnement_id === $original;
            if ($revoque) {
                if ($memeAbonnement) {
                    $user->forceFill(['apple_fin' => null, 'apple_renouvelle' => false])->save();
                }

                return;
            }
            // Transactions dans le désordre : on garde la fin la plus lointaine
            if ($memeAbonnement && $user->apple_fin && $fin && $fin->lessThan($user->apple_fin)) {
                $fin = CarbonImmutable::instance($user->apple_fin);
            }
            $user->forceFill([
                'apple_abonnement_id' => $original,
                'apple_fin' => $fin,
                'apple_renouvelle' => $renouvellement ? ((int) ($renouvellement['autoRenewStatus'] ?? 1)) === 1 : ($user->apple_renouvelle ?? true),
            ])->save();

            return;
        }

        $article = ArticlePlanete::depuisProduitAppStore($produit);
        if (! $article) {
            throw ValidationException::withMessages(['jws' => 'Produit App Store inconnu.']);
        }
        if ($revoque) {
            AchatPlanete::query()->where('apple_transaction_id', $original)->delete();

            return;
        }
        $achat = AchatPlanete::query()->firstOrCreate(['user_id' => $user->id, 'article' => $article]);
        if (! $achat->apple_transaction_id && ! $achat->stripe_facture_id) {
            $achat->forceFill(['apple_transaction_id' => $original])->save();
        }
    }

    private function majore(int $centimes): float
    {
        return round($centimes * self::MAJORATION) / 100;
    }
}
