<?php

namespace App\Services\Patients;

use App\Models\Client;
use App\Models\Patient;
use Illuminate\Validation\ValidationException;

/**
 * Un animal, un seul compte (décision de Jim, 02.11.2026) : le compte de
 * sa fiche à la clinique (clients.user_id) et celui qui l'a réclamé par sa
 * puce (patients.proprietaire_id) sont le même, ou l'un des deux manque.
 * Vérifié à l'enregistrement d'un animal et d'une fiche par les modèles :
 * tout ce qui passe par eux est gardé (une écriture directe en base, comme
 * `DB::table`, y échapperait : l'app n'en fait que pour vider ces liens) ;
 * les écrans posent avant leurs propres messages, plus clairs.
 */
class OneAccountPerPet
{
    /** Le compte d'un animal qui contredit celui de sa fiche, s'il y en a un. */
    public static function conflictFor(?int $accountId, ?int $clientId): bool
    {
        if ($accountId === null || $clientId === null) {
            return false;
        }
        $ficheAccount = Client::withoutGlobalScopes()->whereKey($clientId)->value('user_id');

        return $ficheAccount !== null && (int) $ficheAccount !== $accountId;
    }

    /** Le premier animal de la fiche qui appartient à un autre compte que celui-ci. */
    public static function petOfAnotherAccount(int $clientId, ?int $accountId): ?Patient
    {
        return Patient::withoutGlobalScopes()
            ->where('client_id', $clientId)
            ->whereNotNull('proprietaire_id')
            ->when($accountId !== null, fn ($q) => $q->where('proprietaire_id', '!=', $accountId))
            ->orderBy('id')
            ->first();
    }

    public static function assertPatient(Patient $patient): void
    {
        if (self::conflictFor($patient->proprietaire_id, $patient->client_id)) {
            throw ValidationException::withMessages([
                'proprietaire_id' => 'Un animal n’appartient qu’à un seul compte : celui de sa fiche à la clinique en a déjà un autre.',
            ]);
        }
    }

    public static function assertClient(Client $client): void
    {
        // Une fiche qui se crée n'a pas encore d'animal
        if ($client->exists && $client->user_id !== null && ($pet = self::petOfAnotherAccount($client->id, (int) $client->user_id)) !== null) {
            throw ValidationException::withMessages([
                'client' => $pet->nom.' est déjà dans l’espace d’un autre compte (réclamé par sa puce). Un animal n’appartient qu’à un seul compte.',
            ]);
        }
    }
}
