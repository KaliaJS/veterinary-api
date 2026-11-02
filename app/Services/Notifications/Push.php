<?php

namespace App\Services\Notifications;

use App\Auth\CalculateurPermissions;
use App\Enums\DomainePermission;
use App\Enums\PushType;
use App\Enums\StatutRendezVous;
use App\Jobs\SendPush;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\Employe;
use App\Models\Patient;
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
     * Un rendez-vous confirmé, déplacé ou annulé par la clinique : aux
     * propriétaires qui le voient dans leur espace, avec le jour et l'heure
     * de la clinique. `$userIds` désigne d'autres comptes (ceux qui le
     * perdent, quand l'animal du rendez-vous change).
     *
     * @param  list<int>|null  $userIds
     */
    public static function appointment(RendezVous $rendezVous, string $what, ?int $except = null, bool $isTermine = false, ?array $userIds = null): void
    {
        // Passé, terminé ou absent : plus rien à annoncer (un geste après coup ne réveille personne)
        if ($rendezVous->starts_at->isPast() || in_array($rendezVous->status, [StatutRendezVous::Fini, StatutRendezVous::Noshow], true)
            || (! $isTermine && $what !== 'annulé' && $rendezVous->status === StatutRendezVous::Annule)) {
            return;
        }
        $userIds ??= self::ownersOf($rendezVous);
        if ($userIds === []) {
            return;
        }
        $clinique = Clinique::withoutGlobalScopes()->find($rendezVous->clinique_id);
        $quand = $rendezVous->starts_at->setTimezone($clinique?->fuseau_horaire ?: 'Europe/Zurich')->locale('fr_CH')->isoFormat('ddd D MMM [à] HH:mm');
        $animal = $rendezVous->patient_name ? ' pour '.$rendezVous->patient_name : '';
        self::to($userIds, PushType::Appointment, $clinique->nom ?? 'Rendez-vous', 'Rendez-vous'.$animal.' '.$what.' : '.$quand, '/espace/rendez-vous', $except);
    }

    /**
     * Les comptes qui voient ce rendez-vous dans « Mes rendez-vous » (même
     * règle que l'espace, RendezVousProprietaireController::miens) : celui
     * de sa fiche, celui de son animal (sa fiche, ou rattaché au compte par
     * la puce), et ceux de la demande en ligne qui l'a pris.
     *
     * @return list<int>
     */
    public static function ownersOf(RendezVous $rendezVous): array
    {
        $clientIds = [$rendezVous->client_id];
        $patientIds = [$rendezVous->patient_id];
        $userIds = [];
        foreach (DemandeRendezVous::withoutGlobalScopes()->where('rendez_vous_id', $rendezVous->id)->get(['user_id', 'client_id', 'patient_id']) as $demande) {
            $userIds[] = $demande->user_id;
            $clientIds[] = $demande->client_id;
            $patientIds[] = $demande->patient_id;
        }
        $patientIds = array_values(array_filter($patientIds));
        if ($patientIds !== []) {
            foreach (Patient::withoutGlobalScopes()->whereIn('id', $patientIds)->get(['proprietaire_id', 'client_id']) as $patient) {
                $userIds[] = $patient->proprietaire_id;
                $clientIds[] = $patient->client_id;
            }
        }
        $clientIds = array_values(array_filter($clientIds));
        if ($clientIds !== []) {
            array_push($userIds, ...Client::withoutGlobalScopes()->whereIn('id', $clientIds)->pluck('user_id')->all());
        }

        return collect($userIds)->filter()->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
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
