<?php

namespace App\Services\Videos;

use App\Enums\VideoFilesStatus;
use App\Enums\VideoStatus;
use App\Jobs\PurgeVideoFolder;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoView;
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
    public function __construct(private ViewingCertificates $certificates, private PasserellePaiement $gateway) {}

    /** Efface une vidéo qui n'a aucun acheteur ; ses fichiers partent par la file, qui réessaie. */
    public function erase(Video $video): void
    {
        $folder = $video->folder();
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
        // Des intentions jamais payées : annulées (ouvertes à l'instant, un vieil onglet ne les paiera plus), puis supprimées
        foreach ($video->purchases()->whereNull('paid_at')->whereNotNull('payment_intent_id')->get() as $purchase) {
            try {
                $this->gateway->pourCompte($purchase->stripe_account_id)->annulerIntention((string) $purchase->payment_intent_id);
            } catch (Throwable) {
                // Déjà annulée, ou le prestataire ne répond pas : elle expirera sans rien donner
            }
        }
        $video->purchases()->whereNull('paid_at')->delete();
        if ($video->certificates()->exists()) {
            $video->update([
                'status' => VideoStatus::Withdrawn, 'withdrawn_at' => $video->withdrawn_at ?? now(), 'files_status' => VideoFilesStatus::Rejected,
                'title' => null, 'description' => null, 'seller_name' => '', 'in_plus' => false,
                'size_bytes' => 0, 'renditions' => null, 'download_path' => null, 'upload_started_at' => null,
                'publish_requested_at' => null, 'publish_requested_by' => null,
            ]);
        } else {
            $video->delete();
        }
        PurgeVideoFolder::dispatch($folder)->afterCommit();
    }
}
