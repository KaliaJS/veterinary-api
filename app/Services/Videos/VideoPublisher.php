<?php

namespace App\Services\Videos;

use App\Enums\VideoStatus;
use App\Models\Video;
use App\Paiements\PasserellePaiement;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * La mise en vente elle-même (31.10.2026) : depuis l'écran du vendeur, ou
 * d'elle-même à la fin de la relecture complète quand le vendeur l'a déjà
 * demandée. Fiche complète, vendeur qui vend encore, compte qui encaisse.
 */
class VideoPublisher
{
    public function __construct(private Sellers $sellers, private PasserellePaiement $gateway) {}

    /** Ce qui manque à la fiche, champ par champ (vide : tout y est). */
    public function missing(Video $video): array
    {
        return array_filter([
            'title' => blank($video->title) ? 'Donnez un titre à la vidéo.' : null,
            'description' => blank($video->description) ? 'Décrivez la vidéo.' : null,
            'price_cents' => $video->price_cents === null ? 'Fixez son prix : 2 CHF au moins.' : null,
            'audience' => $video->audience === null ? 'Choisissez à qui elle est destinée.' : null,
        ]);
    }

    /** Met en vente : lève une ValidationException si la fiche ou le vendeur ne le permettent pas. */
    public function publish(Video $video, string $sellerName): void
    {
        $missing = $this->missing($video);
        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
        $this->sellers->assertStillSelling($video);
        // Les ventes encaissent sur un compte prêt
        $account = $this->sellers->stripeAccount($video);
        // La cagnotte Plus se verse par virement : les comptes ouverts avant les vidéos doivent pouvoir en recevoir
        if ($video->in_plus) {
            try {
                $this->gateway->pourCompte(null)->demanderVirementsRecus($account);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $video->update([
            'status' => VideoStatus::Published,
            'published_at' => $video->published_at ?? now(),
            'withdrawn_at' => null,
            'publish_requested_at' => null,
            'seller_name' => $sellerName,
        ]);
    }
}
