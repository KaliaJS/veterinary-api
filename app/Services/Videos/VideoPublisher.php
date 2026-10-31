<?php

namespace App\Services\Videos;

use App\Enums\VideoFilesStatus;
use App\Enums\VideoKind;
use App\Enums\VideoStatus;
use App\Models\Video;
use App\Paiements\PasserellePaiement;
use Illuminate\Support\Facades\DB;
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

    /** Ce qui manque à la fiche, champ par champ (vide : tout y est). Un épisode n'a que son titre et son image : le reste est à sa série. */
    public function missing(Video $video): array
    {
        if ($video->isEpisode()) {
            return array_filter([
                'title' => blank($video->title) ? 'Donnez un titre à l’épisode.' : null,
                'poster' => $video->poster_token === null ? 'Ajoutez une image à l’épisode : une image de la vidéo ou une photo.' : null,
            ]);
        }

        return array_filter([
            'title' => blank($video->title) ? ($video->isSeries() ? 'Donnez un titre à la série.' : 'Donnez un titre à la vidéo.') : null,
            'description' => blank($video->description) ? ($video->isSeries() ? 'Décrivez la série.' : 'Décrivez la vidéo.') : null,
            'price_cents' => $video->price_cents === null ? 'Fixez son prix : 2 CHF au moins.' : null,
            'audience' => $video->audience === null ? 'Choisissez à qui elle est destinée.' : null,
            'category' => $video->category === null ? 'Choisissez sa catégorie.' : null,
            'poster' => $video->poster_token === null ? 'Ajoutez une affiche : une image de la vidéo ou une photo.' : null,
        ]);
    }

    /**
     * Met en vente : lève une ValidationException si la fiche ou le vendeur
     * ne le permettent pas. Une série part avec ses épisodes prêts (relus en
     * entier) ; un épisode s'ajoute à une série déjà en vente.
     */
    public function publish(Video $video, string $sellerName): void
    {
        $missing = $this->missing($video);
        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
        if ($video->isEpisode() && $video->series?->status !== VideoStatus::Published) {
            throw ValidationException::withMessages(['series' => 'La série n’est pas en vente : mettez la série en vente, ses épisodes prêts partent avec elle.']);
        }
        $this->sellers->assertStillSelling($video);
        // Les ventes encaissent sur un compte prêt
        $account = $this->sellers->stripeAccount($video);
        // La cagnotte Plus se verse par virement : les comptes ouverts avant les vidéos doivent pouvoir en recevoir
        if ($video->sellable()->in_plus) {
            try {
                $this->gateway->pourCompte(null)->demanderVirementsRecus($account);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $video->isSeries() ? $this->publishSeries($video, $sellerName) : $this->publishFiles($video, $sellerName);
    }

    /** Une vidéo ou un épisode : seulement si ses fichiers sont toujours prêts et sa fiche toujours complète. */
    private function publishFiles(Video $video, string $sellerName): void
    {
        // Une relecture qui les refuse, ou un autre onglet qui retire l'affiche ou la catégorie, au même moment, l'emporte
        $published = Video::query()->whereKey($video->id)->where('files_status', VideoFilesStatus::Ready->value)
            ->whereNotNull('title')->whereNotNull('poster_token')
            ->when(! $video->isEpisode(), fn ($query) => $query->whereNotNull('description')->whereNotNull('price_cents')->whereNotNull('audience')->whereNotNull('category'))
            ->update([
                'status' => VideoStatus::Published->value,
                'published_at' => $video->published_at ?? now(),
                'withdrawn_at' => null,
                'publish_requested_at' => null,
                'publish_requested_by' => null,
                'seller_name' => $sellerName,
                'updated_at' => now(),
            ]);
        $video->refresh();
        if ($published === 0) {
            $missing = $this->missing($video);
            throw ValidationException::withMessages($missing !== [] ? $missing : ['files' => 'La vidéo n’est plus prête : ses fichiers ont été refusés à la relecture.']);
        }
    }

    /**
     * Une série : elle part avec ses épisodes prêts et relus en entier (au
     * moins un) ; ceux encore en préparation la rejoindront ensuite.
     */
    private function publishSeries(Video $series, string $sellerName): void
    {
        DB::transaction(function () use ($series, $sellerName): void {
            $ready = Video::query()->where('series_id', $series->id)->where('kind', VideoKind::Episode->value)
                ->where('files_status', VideoFilesStatus::Ready->value)->whereNotNull('files_checked_at')
                ->whereNotNull('title')->whereNotNull('poster_token');
            $hasPublished = Video::query()->where('series_id', $series->id)->where('status', VideoStatus::Published->value)->exists();
            if (! $hasPublished && ! (clone $ready)->exists()) {
                throw ValidationException::withMessages(['episodes' => 'Ajoutez au moins un épisode prêt : envoyé, contrôlé et relu, avec son titre et son image.']);
            }
            $published = Video::query()->whereKey($series->id)->where('kind', VideoKind::Series->value)
                ->whereNotNull('title')->whereNotNull('description')->whereNotNull('price_cents')->whereNotNull('audience')
                ->whereNotNull('category')->whereNotNull('poster_token')
                ->update([
                    'status' => VideoStatus::Published->value,
                    'published_at' => $series->published_at ?? now(),
                    'withdrawn_at' => null,
                    'seller_name' => $sellerName,
                    'updated_at' => now(),
                ]);
            if ($published === 0) {
                throw ValidationException::withMessages($this->missing($series->refresh()));
            }
            (clone $ready)->where('status', VideoStatus::Draft->value)->update([
                'status' => VideoStatus::Published->value, 'published_at' => now(), 'seller_name' => $sellerName,
                'publish_requested_at' => null, 'publish_requested_by' => null, 'updated_at' => now(),
            ]);
        });
        $series->refresh();
    }
}
