<?php

namespace App\Auth;

use App\Enums\DomainePermission;
use App\Models\Organisation;
use App\Models\Role;

/**
 * Rôles créés à l'inscription d'une organisation : un point de départ
 * que l'organisation change à sa guise (écran Rôles et droits), pas une
 * liste figée. Défauts du 23.10.2026, validés par Jim :
 * - Administrateur : tout ;
 * - Vétérinaire : tout sauf l'administration, la gestion de l'équipe, la
 *   clôture de la caisse et les remboursements ; il signe ordonnances,
 *   analyses, comptes rendus et stupéfiants ;
 * - ASV : planning, dossiers, caisse (elle encaisse), stocks, bloc, voir
 *   l'équipe ; elle prépare, le vétérinaire signe ;
 * - Assistant : planning, dossiers, stocks, voir l'équipe.
 */
class RolesParDefaut
{
    public const string ADMINISTRATEUR = 'Administrateur';

    public const string VETERINAIRE = 'Vétérinaire';

    public const string ASV = 'ASV';

    public const string ASSISTANT = 'Assistant';

    /**
     * Droits de chaque rôle par défaut.
     *
     * @return array<string, list<DomainePermission>>
     */
    public static function droits(): array
    {
        $tous = DomainePermission::cases();
        $sauf = fn (DomainePermission ...$exclus): array => array_values(array_filter(
            $tous,
            fn (DomainePermission $droit): bool => ! in_array($droit, $exclus, true),
        ));

        return [
            self::ADMINISTRATEUR => $tous,
            self::VETERINAIRE => $sauf(
                DomainePermission::Organisation,
                DomainePermission::Rh,
                DomainePermission::CloturerCaisse,
                DomainePermission::Rembourser,
            ),
            self::ASV => [
                DomainePermission::Planning, DomainePermission::Patients, DomainePermission::Facturation,
                DomainePermission::Stocks, DomainePermission::Bloc, DomainePermission::Equipe,
            ],
            self::ASSISTANT => [
                DomainePermission::Planning, DomainePermission::Patients, DomainePermission::Stocks, DomainePermission::Equipe,
            ],
        ];
    }

    public static function creer(Organisation $organisation): void
    {
        foreach (self::droits() as $nom => $domaines) {
            // Hors scope global : la création de rôles ne dépend
            // jamais du contexte clinique de la requête courante.
            Role::withoutGlobalScope('organisation')->firstOrCreate(
                ['organisation_id' => $organisation->id, 'nom' => $nom],
                ['domaines' => $domaines],
            );
        }
    }
}
