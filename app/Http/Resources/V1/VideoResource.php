<?php

namespace App\Http\Resources\V1;

use App\Models\Video;
use App\Models\VideoView;
use App\Models\ViewingCertificate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une vidéo de formation (31.10.2026). Son vendeur voit aussi l'état de
 * ses fichiers et sa taille ; un acheteur voit ce qu'il peut faire
 * (regarder en entier, télécharger).
 *
 * @mixin Video
 */
class VideoResource extends JsonResource
{
    private ?string $access = null;

    private bool $isSeller = false;

    /** Vue par quelqu'un : ce qu'il peut en faire, et s'il en est le vendeur. */
    /** @var array{watched_seconds: int, counted: bool, completed: bool, certificate_code: ?string}|null */
    private ?array $viewing = null;

    /** Où en est la personne (vidéo ouverte seule) : secondes vues, vue comptée, vue en entier, certificat. */
    public function withViewing(?VideoView $view, ?ViewingCertificate $certificate): self
    {
        $this->viewing = [
            'watched_seconds' => $view->watched_seconds ?? 0,
            'counted' => $view?->counted_at !== null,
            'completed' => $view?->completed_at !== null,
            'certificate_code' => $certificate?->code,
        ];

        return $this;
    }

    public static function seenBy(Video $video, ?string $access, bool $isSeller = false): self
    {
        $resource = new self($video);
        $resource->access = $access;
        $resource->isSeller = $isSeller;

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isSeller = $this->isSeller;
        $access = $this->access;

        $data = [
            'id' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,
            'price_cents' => $this->price_cents,
            'audience' => $this->audience?->value,
            'in_plus' => $this->in_plus,
            'status' => $this->status->value,
            'duration_seconds' => $this->duration_seconds === null ? null : (float) $this->duration_seconds,
            'width' => $this->width,
            'height' => $this->height,
            'seller' => ['name' => $this->seller_name, 'kind' => $this->seller_user_id !== null ? 'vet' : 'clinic'],
            'published_at' => $this->published_at?->toIso8601String(),
            'access' => $access,
            'can_download' => $access === 'owned' && $this->download_path !== null,
        ];
        if ($this->viewing !== null) {
            $data['viewing'] = $this->viewing;
        }
        if ($isSeller) {
            $data['files_status'] = $this->files_status->value;
            $data['publish_requested'] = $this->publish_requested_at !== null;
            $data['rejection_reason'] = $this->rejection_reason;
            $data['size_bytes'] = (int) $this->size_bytes;
            $data['sales_count'] = $this->sales_count ?? $this->purchases()->whereNotNull('paid_at')->count();
        }

        return $data;
    }
}
