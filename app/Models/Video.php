<?php

namespace App\Models;

use App\Enums\VideoAudience;
use App\Enums\VideoFilesStatus;
use App\Enums\VideoStatus;
use Database\Factories\VideoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Vidéo de formation (décision de Jim, 31.10.2026), vendue par un
 * vétérinaire en son nom ou par une clinique. Ses fichiers (HLS, segments
 * CMAF en 1080p au plus et 720p) sont dans le bucket sous `videos/{uuid}/`.
 * Hors d'une organisation : pas de portée par clinique, l'accès se décide
 * par le vendeur, le public, l'achat et Plus.
 *
 * @property VideoStatus $status
 * @property VideoAudience|null $audience
 * @property VideoFilesStatus $files_status
 */
class Video extends Model
{
    /** @use HasFactory<VideoFactory> */
    use HasFactory;

    /** Prix minimum d'une vidéo (décision de Jim, 31.10.2026), sans maximum. */
    public const MIN_PRICE_CENTS = 200;

    /** Durée maximale (3 heures), contrôlée avant l'envoi puis par le serveur. */
    public const MAX_DURATION_SECONDS = 3 * 3600;

    /** Aperçu gratuit : la première minute. */
    public const PREVIEW_SECONDS = 60;

    /** Stockage d'un vétérinaire qui vend en son nom (100 Go). */
    public const PERSONAL_STORAGE_BYTES = 100 * 1000 ** 3;

    protected $fillable = [
        'uuid', 'seller_user_id', 'seller_clinique_id', 'organisation_id', 'seller_name', 'created_by_user_id',
        'title', 'description', 'price_cents', 'audience', 'in_plus', 'status', 'files_status', 'rejection_reason',
        'duration_seconds', 'width', 'height', 'size_bytes', 'renditions', 'download_path',
        'files_verified_at', 'published_at', 'withdrawn_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (Video $video): void {
            $video->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => VideoStatus::class,
            'audience' => VideoAudience::class,
            'files_status' => VideoFilesStatus::class,
            'in_plus' => 'boolean',
            'duration_seconds' => 'decimal:3',
            'renditions' => 'array',
            'files_verified_at' => 'datetime',
            'published_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** Le dossier de ses fichiers dans le bucket. */
    public function folder(): string
    {
        return 'videos/'.$this->uuid;
    }

    /** @return BelongsTo<User, $this> */
    public function sellerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    /** @return BelongsTo<Clinique, $this> */
    public function sellerClinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class, 'seller_clinique_id')->withoutGlobalScopes();
    }

    /** @return HasMany<VideoPurchase, $this> */
    public function purchases(): HasMany
    {
        return $this->hasMany(VideoPurchase::class);
    }

    /** @return HasMany<VideoView, $this> */
    public function views(): HasMany
    {
        return $this->hasMany(VideoView::class);
    }

    /** Vendue par ce vétérinaire en son nom, ou par cette clinique. */
    public function isSoldBy(User $user, ?Clinique $clinique): bool
    {
        if ($this->seller_user_id !== null) {
            return $this->seller_user_id === $user->id;
        }

        return $clinique !== null && $this->seller_clinique_id === $clinique->id;
    }

    /** Achetée (payée) par cette personne. */
    public function isOwnedBy(User $user): bool
    {
        return $this->purchases()->where('user_id', $user->id)->whereNotNull('paid_at')->exists();
    }

    /**
     * Ce que cette personne peut voir au catalogue : une vidéo en vente,
     * pour les clients (tout compte), ou pour les vétérinaires si elle est
     * vétérinaire vérifiée.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public static function visibleTo(Builder $query, User $user): Builder
    {
        $audiences = [VideoAudience::Owners->value, VideoAudience::Both->value];
        if ($user->estVerifie()) {
            $audiences[] = VideoAudience::Vets->value;
        }

        return $query->where('status', VideoStatus::Published->value)->whereIn('audience', $audiences);
    }

    /** Destinée à cette personne : le même filtre que le catalogue, pour une seule vidéo. */
    public function isForAudienceOf(User $user): bool
    {
        if ($this->audience === null) {
            return false;
        }

        return $this->audience->includesOwners() || ($this->audience->includesVets() && $user->estVerifie());
    }
}
