<?php

namespace App\Auth;

use App\Models\Employe;
use Illuminate\Support\Str;

/**
 * Abilities des jetons d'API, par domaine fonctionnel. Les rôles ne
 * sont pas encore tranchés (docs/data-model.md, décisions ouvertes) :
 * seule la structure est posée, avec la contrainte connue — une
 * assistante (ASV) ne voit pas la facturation. La résolution se fait
 * à l'émission ET au rafraîchissement du jeton, jamais figée ailleurs.
 */
final class Abilites
{
    public const string PLANNING = 'planning';

    public const string PATIENTS = 'patients';

    public const string FACTURATION = 'facturation';

    public const string STOCKS = 'stocks';

    public const string BLOC = 'bloc';

    public const string EQUIPE = 'equipe';

    /** @return list<string> */
    public static function toutes(): array
    {
        return [
            self::PLANNING,
            self::PATIENTS,
            self::FACTURATION,
            self::STOCKS,
            self::BLOC,
            self::EQUIPE,
        ];
    }

    /**
     * Abilities d'un employé selon son rôle dans la clinique.
     *
     * Le rôle est aujourd'hui un libellé libre (« Vétérinaire »,
     * « ASV · Accueil »…) : un vétérinaire obtient tout, tout autre
     * rôle obtient tout sauf la facturation — défaut prudent en
     * attendant des rôles configurables.
     *
     * @return list<string>
     */
    public static function pour(Employe $employe): array
    {
        if (Str::startsWith($employe->role, 'Vétérinaire')) {
            return self::toutes();
        }

        return array_values(array_diff(self::toutes(), [self::FACTURATION]));
    }
}
