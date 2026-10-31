<?php

namespace App\Console\Commands;

use App\Models\SellerAccountClosure;
use App\Models\Video;
use App\Models\VideoPurchase;
use App\Paiements\ErreurPaiement;
use App\Paiements\PasserellePaiement;
use App\Services\Videos\SellerlessVideos;
use App\Services\Videos\VideoPurchases;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Les comptes de paiement des vendeurs effacés (31.10.2026, douzième
 * vérification), chaque nuit : les paiements encore en cours à la banque
 * sont relus (payés, la vidéo reste à son acheteur ; tombés, une vidéo
 * sans acheteur s'efface enfin) ; quand plus rien n'y est en cours, le
 * compte se ferme, une fois son solde versé au vendeur. Un compte qui
 * résiste 60 jours est signalé, à fermer à la main.
 */
class CloseSellerAccounts extends Command
{
    protected $signature = 'videos:close-seller-accounts';

    protected $description = 'Ferme les comptes de paiement des vendeurs effacés, une fois leurs paiements en cours conclus';

    /** Au-delà, un compte qui ne se ferme pas est signalé pour une fermeture à la main */
    private const REPORT_AFTER_DAYS = 60;

    public function handle(PasserellePaiement $gateway, VideoPurchases $purchases, SellerlessVideos $videos): int
    {
        SellerAccountClosure::query()->whereNull('closed_at')->orderBy('id')->each(function (SellerAccountClosure $closure) use ($gateway, $purchases, $videos): void {
            try {
                $pending = $this->settlePending($closure->stripe_account_id, $purchases, $videos);
                if ($pending === 0) {
                    $this->close($closure, $gateway);
                }
            } catch (Throwable $e) {
                // Une panne : ce compte attend la nuit suivante, les autres passent
                report($e);
            }
            if ($closure->closed_at === null && $closure->reported_at === null && $closure->requested_at < now()->subDays(self::REPORT_AFTER_DAYS)) {
                report(new RuntimeException("Compte de paiement {$closure->stripe_account_id} d'un vendeur effacé toujours ouvert après ".self::REPORT_AFTER_DAYS.' jours : à fermer à la main'.($closure->last_error ? " ({$closure->last_error})" : '')));
                $closure->update(['reported_at' => now()]);
            }
        });

        return self::SUCCESS;
    }

    /** Relit les paiements encore ouverts sur ce compte ; rend combien restent en cours. */
    private function settlePending(string $account, VideoPurchases $purchases, SellerlessVideos $videos): int
    {
        $pending = 0;
        $open = VideoPurchase::query()->whereNull('paid_at')->whereNotNull('payment_intent_id')->where('stripe_account_id', $account)->get();
        foreach ($open as $purchase) {
            $state = $purchases->confirm($purchase);
            if ($state['paid']) {
                continue;
            }
            if (! $state['failed']) {
                $pending++;

                continue;
            }
            // Tombé : rien n'a été débité ; une vidéo sans vendeur que plus rien ne retient s'efface
            $video = Video::query()->find($purchase->video_id);
            $purchase->delete();
            if ($video !== null && $video->seller_user_id === null && $video->seller_clinique_id === null
                && ! $video->purchases()->where(fn ($held) => $held->whereNotNull('paid_at')->orWhereNotNull('payment_intent_id'))->exists()) {
                $videos->erase($video);
            }
        }

        return $pending;
    }

    private function close(SellerAccountClosure $closure, PasserellePaiement $gateway): void
    {
        $platform = $gateway->pourCompte(null);
        // Déjà fermé (une réponse perdue une nuit d'avant) : noté, sans le refermer
        if ($platform->compteVendeurFerme($closure->stripe_account_id)) {
            $closure->update(['closed_at' => now()]);

            return;
        }
        try {
            $platform->fermerCompteVendeur($closure->stripe_account_id);
            $closure->update(['closed_at' => now()]);
        } catch (ErreurPaiement $e) {
            if ($e->estPassagere() || $e->cleRefusee()) {
                throw $e;
            }
            // Refusé (solde pas encore versé au vendeur…) : retenté la nuit suivante
            $closure->update(['attempts' => $closure->attempts + 1, 'last_error' => $e->codeStripe ?? $e->getMessage()]);
        }
    }
}
