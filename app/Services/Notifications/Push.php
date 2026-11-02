<?php

namespace App\Services\Notifications;

use App\Auth\CalculateurPermissions;
use App\Enums\DomainePermission;
use App\Enums\PushType;
use App\Enums\StatutRendezVous;
use App\Jobs\SendPush;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\RendezVous;

/**
 * Les notifications du téléphone (02.11.2026) : une par compte, mise en
 * file après l'enregistrement (rien ne part si la transaction échoue).
 */
class Push
{
    /** @param  iterable<int|null>  $userIds */
    public static function to(iterable $userIds, PushType $type, string $title, string $body, ?string $url = null, ?int $except = null): void
    {
        foreach (collect($userIds)->filter()->unique() as $userId) {
            if ($userId === $except) {
                continue;
            }
            SendPush::dispatch((int) $userId, $type->value, $title, $body, $url)->afterCommit();
        }
    }

    /**
     * Un rendez-vous confirmé, déplacé ou annulé par la clinique : au
     * propriétaire qui a un compte, avec le jour et l'heure de la clinique.
     */
    public static function appointment(RendezVous $rendezVous, string $what, ?int $except = null, bool $isTermine = false): void
    {
        // Passé, terminé ou absent : plus rien à annoncer (un geste après coup ne réveille personne)
        if ($rendezVous->starts_at->isPast() || in_array($rendezVous->status, [StatutRendezVous::Fini, StatutRendezVous::Noshow], true)
            || (! $isTermine && $what !== 'annulé' && $rendezVous->status === StatutRendezVous::Annule)) {
            return;
        }
        $userId = $rendezVous->client_id ? Client::withoutGlobalScopes()->whereKey($rendezVous->client_id)->value('user_id') : null;
        if ($userId === null) {
            return;
        }
        $clinique = Clinique::withoutGlobalScopes()->find($rendezVous->clinique_id);
        $quand = $rendezVous->starts_at->setTimezone($clinique?->fuseau_horaire ?: 'Europe/Zurich')->locale('fr_CH')->isoFormat('ddd D MMM [à] HH:mm');
        $animal = $rendezVous->patient_name ? ' pour '.$rendezVous->patient_name : '';
        self::to([(int) $userId], PushType::Appointment, $clinique->nom ?? 'Rendez-vous', 'Rendez-vous'.$animal.' '.$what.' : '.$quand, '/espace/rendez-vous', $except);
    }

    /**
     * Les comptes de l'équipe d'une clinique qui ont ce droit : affectés à
     * la clinique, pas suspendus, avec un compte.
     *
     * @return list<int>
     */
    public static function staffOf(int $cliniqueId, DomainePermission $permission): array
    {
        $calcul = app(CalculateurPermissions::class);

        // Rôles et dérogations lus hors du contexte de la requête : la clinique d'à côté peut être d'une autre organisation
        return Employe::withoutGlobalScopes()
            ->with(['role' => fn ($q) => $q->withoutGlobalScopes(), 'derogations' => fn ($q) => $q->withoutGlobalScopes()])
            ->whereNotNull('user_id')
            ->where('acces_suspendu', false)
            ->whereHas('cliniques', fn ($q) => $q->withoutGlobalScopes()->where('cliniques.id', $cliniqueId))
            ->get()
            ->filter(fn (Employe $e): bool => in_array($permission->value, $calcul->pour($e), true))
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }
}
