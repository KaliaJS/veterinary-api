<?php

namespace App\Services\Messages;

use App\Models\MessageMute;
use Illuminate\Database\Eloquent\Builder;

/**
 * Le mode silencieux des conversations (décision de Jim, 08.11.2026), pour
 * une personne sur tous ses appareils. Entre collègues (« e… »), le fil
 * suit la personne d'une clinique à l'autre : aucune clinique n'est notée.
 */
final class MessageMutes
{
    /** La clinique notée pour ce fil : aucune entre collègues */
    private static function clinicFor(string $thread, ?int $cliniqueId): ?int
    {
        return $thread[0] === 'e' ? null : $cliniqueId;
    }

    /** @return Builder<MessageMute> */
    private static function of(int $userId, ?int $cliniqueId, string $thread): Builder
    {
        $clinic = self::clinicFor($thread, $cliniqueId);

        return MessageMute::query()->where('user_id', $userId)->where('thread', $thread)
            ->when($clinic === null, fn (Builder $q) => $q->whereNull('clinique_id'), fn (Builder $q) => $q->where('clinique_id', $clinic));
    }

    public function set(int $userId, ?int $cliniqueId, string $thread, bool $isMuted): void
    {
        if (! $isMuted) {
            self::of($userId, $cliniqueId, $thread)->delete();

            return;
        }
        MessageMute::query()->firstOrCreate(['user_id' => $userId, 'clinique_id' => self::clinicFor($thread, $cliniqueId), 'thread' => $thread]);
    }

    public function isMuted(int $userId, ?int $cliniqueId, string $thread): bool
    {
        return self::of($userId, $cliniqueId, $thread)->exists();
    }

    /**
     * Les fils en silence de cette personne, dans cette clinique (et ceux
     * entre collègues), en une requête pour toute la liste.
     *
     * @return array<string, bool>
     */
    public function threadsOf(int $userId, ?int $cliniqueId): array
    {
        return MessageMute::query()->where('user_id', $userId)
            ->where(fn (Builder $q) => $q->whereNull('clinique_id')->when($cliniqueId !== null, fn (Builder $q) => $q->orWhere('clinique_id', $cliniqueId)))
            ->pluck('thread')->mapWithKeys(fn (string $t): array => [$t => true])->all();
    }

    /**
     * Ceux qui seront prévenus : les comptes qui n'ont pas mis ce fil en silence.
     *
     * @param  list<int>  $userIds
     * @return list<int>
     */
    public function audible(array $userIds, ?int $cliniqueId, string $thread): array
    {
        if ($userIds === []) {
            return [];
        }
        $clinic = self::clinicFor($thread, $cliniqueId);
        $muted = MessageMute::query()->whereIn('user_id', $userIds)->where('thread', $thread)
            ->when($clinic === null, fn (Builder $q) => $q->whereNull('clinique_id'), fn (Builder $q) => $q->where('clinique_id', $clinic))
            ->pluck('user_id')->map(fn ($id): int => (int) $id)->all();

        return array_values(array_diff($userIds, $muted));
    }
}
