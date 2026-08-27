<?php

namespace App\Auth;

use App\Enums\DomainePermission;
use App\Models\Organisation;
use App\Models\Role;

/**
 * Rôles créés à l'inscription d'une organisation — un point de départ
 * modifiable, pas une liste figée. Contrainte connue
 * (docs/data-model.md) : une assistante ne voit pas la facturation ;
 * l'assistant (AMV du proto) n'a pas non plus le bloc. Le domaine
 * `organisation` (structure, abonnement SaaS) est réservé à
 * l'Administrateur — l'écran Organisation du proto est le sien.
 */
class RolesParDefaut
{
    public const string ADMINISTRATEUR = 'Administrateur';

    public const string VETERINAIRE = 'Vétérinaire';

    public const string ASV = 'ASV';

    public const string ASSISTANT = 'Assistant';

    public static function creer(Organisation $organisation): void
    {
        $tous = DomainePermission::cases();

        $sauf = fn (DomainePermission ...$exclus): array => array_values(array_udiff(
            $tous,
            $exclus,
            fn (DomainePermission $a, DomainePermission $b): int => strcmp($a->value, $b->value),
        ));

        $roles = [
            self::ADMINISTRATEUR => $tous,
            self::VETERINAIRE => $sauf(DomainePermission::Organisation),
            self::ASV => $sauf(DomainePermission::Facturation, DomainePermission::Organisation),
            self::ASSISTANT => $sauf(DomainePermission::Facturation, DomainePermission::Bloc, DomainePermission::Organisation),
        ];

        foreach ($roles as $nom => $domaines) {
            // Hors scope global : la création de rôles ne dépend
            // jamais du contexte clinique de la requête courante.
            Role::withoutGlobalScope('organisation')->firstOrCreate(
                ['organisation_id' => $organisation->id, 'nom' => $nom],
                ['domaines' => $domaines],
            );
        }
    }
}
