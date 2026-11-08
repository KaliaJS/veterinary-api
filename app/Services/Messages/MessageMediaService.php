<?php

namespace App\Services\Messages;

use App\Models\MessageClientele;
use App\Models\MessageMedia;
use App\Models\Organisation;
use App\Services\Transcription\ConsommationTranscription;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Messages audio et vidéo façon Telegram (demande de Jim, 04.11.2026 ; feu
 * vert du 06.11.2026), dans le fil d'un client, entre collègues et entre
 * cliniques (décision de Jim, 07.11.2026) : le fichier rangé sur le
 * disque privé comme les autres fichiers de l'app, sa durée et son onde
 * servies avec le message, sa transcription servie aux équipes seulement,
 * jamais au propriétaire.
 *
 * Le texte du message dit ce que c'est (« Message audio ») : l'aperçu de
 * la liste, l'e-mail, la notification et une ancienne version de l'app le
 * lisent tel quel.
 */
final class MessageMediaService
{
    public function __construct(private ConsommationTranscription $consommation) {}

    /** Les conteneurs acceptés par genre : ce que Chrome, Firefox et Safari enregistrent */
    private const array CONTAINERS = [
        'audio' => ['webm', 'mp4', 'ogg'],
        'video' => ['webm', 'mp4'],
    ];

    private const array EXTENSIONS = ['webm' => 'webm', 'mp4' => 'mp4', 'ogg' => 'ogg'];

    public static function label(string $kind): string
    {
        return $kind === 'video' ? 'Message vidéo' : 'Message audio';
    }

    /**
     * Le conteneur d'après les premiers octets : WebM (en-tête EBML de
     * DocType « webm », un Matroska quelconque est refusé), MP4 (boîte
     * `ftyp` d'une marque d'enregistrement, pas une image HEIC ou AVIF), Ogg.
     */
    public static function container(string $head): ?string
    {
        return match (true) {
            str_starts_with($head, "\x1A\x45\xDF\xA3") && MediaDuration::webmDocType($head) === 'webm' => 'webm',
            strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), config('messages.media.mp4_brands'), true) => 'mp4',
            str_starts_with($head, 'OggS') => 'ogg',
            default => null,
        };
    }

    /** Le type servi pour ce genre et ces premiers octets, ou null si le fichier n'en est pas un */
    public static function mime(string $kind, string $head): ?string
    {
        $container = self::container($head);
        if ($container === null || ! in_array($container, self::CONTAINERS[$kind] ?? [], true)) {
            return null;
        }

        return $kind.'/'.$container;
    }

    /**
     * Pourquoi ce fichier ne se transcrit pas, ou null : une vidéo (décision
     * de Jim, 07.11.2026 : l'audio seulement), un fichier effacé avec le
     * compte, ou plus long ou plus lourd que ne le permet un message audio
     * (rien qui dure des heures ne part chez Deepgram).
     */
    public static function untranscribable(MessageMedia $media): ?string
    {
        $limits = config('messages.media.audio');

        return match (true) {
            $media->kind !== 'audio' => 'Seuls les messages audio se transcrivent.',
            $media->erased_at !== null || $media->path === null => 'Le fichier de ce message a été effacé avec le compte.',
            $media->size_bytes > $limits['max_kb'] * 1024,
            $media->duration_ms > $limits['max_seconds'] * 1000 + (int) config('messages.media.tolerance_ms') => 'Ce fichier dépasse ce que permet un message audio : il ne se transcrit pas.',
            default => null,
        };
    }

    /**
     * Un message du fil d'un client et son fichier.
     *
     * @param  array<string, mixed>  $message  les colonnes du message (clinique, compte, fiche, auteur…)
     * @param  array<int|string, float|int|string>  $peaks  l’onde validée (de 0 à 1)
     */
    public function store(array $message, UploadedFile $file, string $kind, int $durationMs, array $peaks, ?UploadedFile $poster = null): MessageClientele
    {
        return $this->storeWith(
            fn (string $label): MessageClientele => MessageClientele::query()->create([...$message, 'texte' => $label]),
            'message_clientele_id', (int) $message['clinique_id'], $file, $kind, $durationMs, $peaks, $poster,
        );
    }

    /**
     * Le message (créé par `$create`, qui reçoit son texte) et son fichier,
     * ensemble : le fichier est rangé d'abord, et effacé si le message ne
     * s'écrit pas (droit refusé, base en panne).
     *
     * @template TMessage of Model
     *
     * @param  Closure(string): TMessage  $create
     * @param  string  $foreignKey  message_clientele_id, staff_message_id ou clinic_message_id
     * @param  int  $cliniqueId  la clinique d'où il part
     * @param  array<int|string, float|int|string>  $peaks  l’onde validée (de 0 à 1)
     * @return TMessage
     */
    public function storeWith(Closure $create, string $foreignKey, int $cliniqueId, UploadedFile $file, string $kind, int $durationMs, array $peaks, ?UploadedFile $poster = null): Model
    {
        $head = (string) @file_get_contents((string) $file->getRealPath(), false, null, 0, 64);
        $mime = self::mime($kind, $head) ?? throw new RuntimeException('Fichier non vérifié.');
        $path = $file->storeAs('messages/'.$cliniqueId, Str::uuid()->toString().'.'.self::EXTENSIONS[explode('/', $mime)[1]], 'prive');
        if ($path === false) {
            throw new RuntimeException('Le fichier du message n’a pas pu être rangé.');
        }
        // La première image d'une vidéo (08.11.2026), réencodée ; illisible, la vidéo part sans elle
        $posterPath = null;
        $posterBytes = $kind === 'video' && $poster !== null ? MessageAttachmentService::smallJpeg((string) file_get_contents((string) $poster->getRealPath()), 480) : null;
        if ($posterBytes !== null) {
            $posterPath = 'messages/'.$cliniqueId.'/'.Str::uuid()->toString().'.jpg';
            if (! Storage::disk('prive')->put($posterPath, $posterBytes)) {
                $posterPath = null;
            }
        }

        try {
            return DB::transaction(function () use ($create, $foreignKey, $kind, $path, $posterPath, $mime, $file, $durationMs, $peaks, $cliniqueId): Model {
                $created = $create(self::label($kind));
                $created->setRelation('media', MessageMedia::query()->create([
                    $foreignKey => $created->getKey(),
                    'clinique_id' => $cliniqueId,
                    'kind' => $kind,
                    'path' => $path,
                    'poster_path' => $posterPath,
                    'mime' => $mime,
                    'size_bytes' => (int) $file->getSize(),
                    'duration_ms' => $durationMs,
                    'peaks' => array_map(fn ($v): float => round((float) $v, 2), array_values($peaks)),
                ]));

                return $created;
            });
        } catch (Throwable $e) {
            Storage::disk('prive')->delete(array_values(array_filter([$path, $posterPath])));
            throw $e;
        }
    }

    /**
     * Le compteur du jour d'une organisation (heure suisse) : les secondes
     * payées à Deepgram pour ses messages, plus celles réservées par les
     * transcriptions en cours (cinquième vérification, 07.11.2026).
     *
     * @return array{cle: string, mois: string, jour: string}
     */
    private static function dayCounter(int $organisationId, ?string $day = null): array
    {
        $day ??= CarbonImmutable::now('Europe/Zurich')->toDateString();

        return ['cle' => 'messages-deepgram:'.$organisationId.':'.$day, 'mois' => substr($day, 0, 7), 'jour' => $day];
    }

    /** Les secondes payées et réservées aujourd'hui pour les messages de cette organisation */
    public static function providerSecondsToday(int $organisationId): int
    {
        $counter = self::dayCounter($organisationId);

        return max(0, (int) DB::table('compteurs_mensuels')->where('cle', $counter['cle'])->where('mois', $counter['mois'])->value('valeur'));
    }

    /** Ce qui reste aujourd'hui à l'organisation, en secondes payées à Deepgram */
    public static function providerSecondsLeftToday(int $organisationId): int
    {
        return max(0, (int) config('messages.media.daily_provider_seconds') - self::providerSecondsToday($organisationId));
    }

    /**
     * Réserve, sous verrou de la ligne du jour, l'estimation d'une
     * transcription dans ce qui reste à l'organisation : plusieurs tâches
     * (plusieurs workers) ne prennent jamais deux fois le même reste. Rend la
     * réservation, ou null quand il ne reste rien (la tâche attend le lendemain).
     *
     * @return array{cle: string, mois: string, jour: string, secondes: int}|null
     */
    public static function reserveToday(int $organisationId, int $estimate): ?array
    {
        $counter = self::dayCounter($organisationId);

        return DB::transaction(function () use ($counter, $estimate): ?array {
            DB::table('compteurs_mensuels')->insertOrIgnore(['cle' => $counter['cle'], 'mois' => $counter['mois'], 'valeur' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $used = (int) DB::table('compteurs_mensuels')->where('cle', $counter['cle'])->where('mois', $counter['mois'])->lockForUpdate()->value('valeur');
            $left = (int) config('messages.media.daily_provider_seconds') - $used;
            if ($left <= 0) {
                return null;
            }
            $seconds = max(1, min($estimate, $left));
            DB::table('compteurs_mensuels')->where('cle', $counter['cle'])->where('mois', $counter['mois'])->increment('valeur', $seconds, ['updated_at' => now()]);

            return [...$counter, 'secondes' => $seconds];
        });
    }

    /**
     * La réponse de Deepgram : la réservation devient la durée payée (0 pour
     * un refus net, rien n'est facturé).
     *
     * @param  array{cle: string, mois: string, jour: string, secondes: int}  $reservation
     */
    public static function settleToday(array $reservation, int $paidSeconds): void
    {
        $delta = $paidSeconds - $reservation['secondes'];
        if ($delta !== 0) {
            DB::table('compteurs_mensuels')->where('cle', $reservation['cle'])->where('mois', $reservation['mois'])
                ->increment('valeur', $delta, ['updated_at' => now()]);
        }
    }

    /**
     * Le plafond du jour atteint (quatrième vérification) : un fichier piégé
     * ne vide pas le budget Deepgram de toute la plateforme.
     */
    public static function dailyRefusal(int $organisationId): ?string
    {
        if (self::providerSecondsLeftToday($organisationId) > 0) {
            return null;
        }
        $cap = (int) config('messages.media.daily_provider_seconds');

        return sprintf('La transcription des messages de votre organisation a atteint sa limite du jour (%d:%02d:%02d de son). Elle reprend demain.', intdiv($cap, 3600), intdiv($cap % 3600, 60), $cap % 60);
    }

    /**
     * Le fichier servi : son type tel que reconnu à l'envoi, sans que le
     * navigateur le devine (`nosniff`) ; effacé avec le compte, il n'est plus là (410).
     */
    public static function response(MessageMedia $media, bool $isPoster = false): StreamedResponse
    {
        if ($media->erased_at !== null || $media->path === null) {
            abort(410, 'Ce fichier a été effacé avec le compte de son auteur.');
        }
        // La première image d'une vidéo (08.11.2026)
        $path = $isPoster ? ($media->poster_path ?? abort(404)) : $media->path;

        return Storage::disk('prive')->response($path, null, [
            'Content-Type' => $isPoster ? 'image/jpeg' : $media->mime,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * La clinique paie (abonnement en cours et réglé, identité vérifiée) :
     * son équipe a le bouton de transcription. Les plafonds et le budget ne
     * cachent pas le bouton : ils se disent quand on le touche.
     */
    public function clinicPays(?Organisation $organisation): bool
    {
        return $organisation !== null && $this->consommation->abonnementRefuse($organisation) === null;
    }

    /**
     * Pour l'équipe de la clinique : avec la transcription, et le bouton
     * quand la clinique paie, ou quand le texte est déjà là (relu sans repayer).
     *
     * @return array<string, mixed>
     */
    public static function forClinic(MessageMedia $media, bool $clinicPays): array
    {
        $isAudio = $media->kind === 'audio';

        return [
            ...self::forOwner($media),
            // L'audio seulement ; un texte déjà là se relit toujours, sans repayer
            'can_transcribe' => $isAudio && ($media->isTranscribed() || ($clinicPays && $media->erased_at === null)),
            'transcript' => ! $isAudio || $media->transcript_status === null ? null : [
                'status' => $media->transcript_status,
                'text' => $media->isTranscribed() ? $media->transcript : null,
            ],
        ];
    }

    /**
     * Pour le propriétaire : le fichier, sa durée et son onde ; la
     * transcription reste un outil de la clinique.
     *
     * @return array<string, mixed>
     */
    public static function forOwner(MessageMedia $media): array
    {
        return [
            'id' => $media->id,
            'kind' => $media->kind,
            'mime' => $media->mime,
            'duration_ms' => $media->duration_ms,
            'peaks' => array_map(fn ($v): float => (float) $v, $media->peaks ?? []),
            'size_bytes' => $media->size_bytes,
            // La première image d'une vidéo, à montrer avant la lecture (08.11.2026)
            'has_poster' => $media->poster_path !== null && $media->erased_at === null,
            // Effacé avec le compte de son auteur : la bulle le dit
            'erased' => $media->erased_at !== null,
        ];
    }
}
