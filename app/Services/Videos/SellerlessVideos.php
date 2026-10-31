<?php

namespace App\Services\Videos;

use App\Enums\VideoFilesStatus;
use App\Enums\VideoStatus;
use App\Jobs\PurgeVideoFolder;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoView;
use App\Paiements\ErreurPaiement;
use App\Paiements\PasserellePaiement;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Les vidéos d'un vendeur qui a effacé son compte (31.10.2026) : sans
 * acheteur, elles s'effacent avec leurs fichiers ; la fiche reste vide si
 * des certificats délivrés s'y rattachent, pour qu'ils se vérifient encore.
 * Qui l'a vue en entier sans avoir demandé son certificat le reçoit avant.
 */
class SellerlessVideos
{
    public function __construct(private ViewingCertificates $certificates, private PasserellePaiement $gateway, private VideoPurchases $purchases, private VideoPosters $posters) {}

    /**
     * Efface une vidéo qui n'a aucun acheteur ; ses fichiers partent par la
     * file, qui réessaie. Un paiement conclu au même instant (l'annulation
     * refusée, relu payé) l'arrête : faux, la vidéo reste à son acheteur.
     */
    public function erase(Video $video): bool
    {
        // Des intentions encore ouvertes : annulées (un vieil onglet ne les paiera plus). Une qui ne
        // s'annule plus est relue : seulement tombée, la vidéo s'efface ; payée, en cours à la banque ou
        // illisible, elle reste (la tâche de nuit des comptes à fermer la relira)
        foreach ($video->purchases()->whereNull('paid_at')->whereNotNull('payment_intent_id')->get() as $purchase) {
            try {
                $this->gateway->pourCompte($purchase->stripe_account_id)->annulerIntention((string) $purchase->payment_intent_id);
            } catch (ErreurPaiement $e) {
                if ($e->estPassagere() || $e->cleRefusee()) {
                    throw $e;
                }
                if (! $this->purchases->confirm($purchase)['failed']) {
                    return false;
                }
            } catch (Throwable) {
                return false;
            }
        }
        $folder = $video->folder();
        $poster = $video->poster_token;
        $certified = $video->certificates()->pluck('user_id')->all();
        $video->views()->whereNotNull('completed_at')->whereNotIn('user_id', $certified)->get()
            ->each(function (VideoView $view) use ($video): void {
                $person = User::query()->find($view->user_id);
                if ($person === null) {
                    return;
                }
                try {
                    $this->certificates->issue($video, $person);
                } catch (ValidationException) {
                    // Sa propre vidéo, ou plus regardable : pas de certificat
                }
            });
        $video->views()->delete();
        $video->purchases()->whereNull('paid_at')->delete();
        if ($video->certificates()->exists()) {
            $video->update([
                'status' => VideoStatus::Withdrawn, 'withdrawn_at' => $video->withdrawn_at ?? now(), 'files_status' => VideoFilesStatus::Rejected,
                'title' => null, 'description' => null, 'seller_name' => '', 'in_plus' => false, 'poster_token' => null, 'poster_preview' => null,
                'size_bytes' => 0, 'renditions' => null, 'download_path' => null, 'upload_started_at' => null,
                'publish_requested_at' => null, 'publish_requested_by' => null,
            ]);
        } else {
            $video->delete();
        }
        PurgeVideoFolder::dispatch($folder)->afterCommit();
        $this->posters->delete($poster);

        return true;
    }
}
