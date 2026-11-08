<?php

namespace App\Services\Messages;

use App\Events\MessagesChangent;
use App\Models\ClinicMessage;
use App\Models\Employe;
use App\Models\MessageClientele;
use App\Models\StaffMessage;
use App\Support\TempsReel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Les coches des messages façon WhatsApp (décision de Jim, 08.11.2026).
 *
 * - « sent » : enregistré par le serveur, une coche grise ;
 * - « delivered » : l'app du destinataire l'a reçu (elle a relu ses
 *   messages ou leur nombre), deux coches grises ;
 * - « read » : le destinataire a ouvert le fil, deux coches bleues. Dans
 *   un fil avec une autre clinique, lu par quelqu'un de son équipe.
 *
 * Chaque changement prévient l'expéditeur par Reverb (sans contenu), et
 * seulement quand une ligne a vraiment changé : la relecture qu'il
 * déclenche ne change plus rien, aucune boucle.
 */
final class MessageReceipts
{
    public static function status(?CarbonInterface $deliveredAt, ?CarbonInterface $readAt): string
    {
        return $readAt !== null ? 'read' : ($deliveredAt !== null ? 'delivered' : 'sent');
    }

    /** L'app du propriétaire a relu ses messages : ceux des cliniques sont distribués, chaque clinique le sait */
    public function ownerReceived(int $userId): void
    {
        $this->received(
            MessageClientele::query()->where('user_id', $userId)->where('auteur', 'clinique'),
            fn (MessageClientele $m): array => ['clinique.'.$m->clinique_id, $userId],
        );
    }

    /** L'app d'un membre de la clinique a relu la liste : les messages des propriétaires sont distribués */
    public function clinicReceivedFromOwners(int $cliniqueId): void
    {
        $this->received(
            MessageClientele::query()->where('clinique_id', $cliniqueId)->where('auteur', 'proprietaire'),
            // Un compte effacé : personne à prévenir
            fn (MessageClientele $m): ?array => $m->user_id ? ['utilisateur.'.$m->user_id, (int) $m->user_id] : null,
        );
    }

    /** Les messages des collègues à cet employé */
    public function staffReceived(Employe $me): void
    {
        $this->received(
            StaffMessage::query()->where('recipient_employee_id', $me->id)->with('sender'),
            fn (StaffMessage $m): ?array => $m->sender?->user_id ? ['utilisateur.'.$m->sender->user_id, null] : null,
        );
    }

    /** Les messages des autres cliniques à celle-ci */
    public function clinicReceived(int $cliniqueId): void
    {
        $this->received(
            ClinicMessage::query()->where('recipient_clinic_id', $cliniqueId),
            fn (ClinicMessage $m): array => ['clinique.'.$m->sender_clinic_id, null],
        );
    }

    /**
     * Lus (le fil ouvert) : distribués au plus tard maintenant ; l'expéditeur
     * est prévenu sur `$channel` (aucun : un compte effacé) quand quelque chose a changé.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $unread  les messages reçus de ce fil
     */
    public function read(Builder $unread, string $readColumn, ?string $channel, ?int $userId = null): void
    {
        $now = now();
        (clone $unread)->whereNull($readColumn)->whereNull('delivered_at')->update(['delivered_at' => $now]);
        if ((clone $unread)->whereNull($readColumn)->update([$readColumn => $now]) > 0 && $channel !== null) {
            TempsReel::diffuser(fn () => MessagesChangent::dispatch($channel, $userId));
        }
    }

    /**
     * Les messages pas encore distribués de `$query` le deviennent ; chaque
     * expéditeur (son canal, par `$notify`) est prévenu une fois.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  callable(TModel): (array{0: string, 1: ?int}|null)  $notify
     */
    private function received(Builder $query, callable $notify): void
    {
        $pending = (clone $query)->whereNull('delivered_at')->get();
        if ($pending->isEmpty()) {
            return;
        }
        $query->getModel()->newQuery()->whereKey($pending->modelKeys())->whereNull('delivered_at')->update(['delivered_at' => now()]);

        $pending->map($notify)->filter()->unique(fn (array $c): string => $c[0])
            ->each(fn (array $c) => TempsReel::diffuser(fn () => MessagesChangent::dispatch($c[0], $c[1])));
    }
}
