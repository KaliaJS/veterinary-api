<?php

namespace App\Http\Requests\V1\Equipe;

use App\Enums\StatutAbsence;
use App\Enums\TypeAbsence;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use App\Models\Absence;
use App\Models\Employe;
use App\Support\DateSaisie;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Le vétérinaire d'une nuit de garde, à venir ou des 30 derniers jours
 * (É28 : corriger le registre, qui l'a vraiment faite). Une nuit à venir :
 * sans employé, elle revient à la suggestion ; le samedi et le dimanche se
 * donnent ensemble (jamais une nuit passée avec) ; son vétérinaire a son
 * accès. Une nuit passée : une correction, seule, jamais effacée, qui peut
 * nommer quelqu'un parti depuis. Le vétérinaire n'est absent ni hors
 * contrat aucune de ces nuits.
 */
class ModifierGardeRequest extends FormRequest
{
    use ReglesScopees;

    /** Une nuit passée se corrige pendant 30 jours (É28) */
    public const CORRECTION_JOURS = 30;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $estPassee = DateSaisie::lire($this->input('jour'))?->lt(aujourdhui()) ?? false;

        return [
            'jour' => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:'.aujourdhui()->subDays(self::CORRECTION_JOURS)->toDateString(), 'before_or_equal:'.aujourdhui()->addYears(3)->toDateString()],
            'employe_id' => [
                'bail',
                // Le registre se corrige, il ne s'efface pas
                $estPassee ? 'required' : 'nullable',
                $this->employeDeLaClinique(),
                function (string $attribut, mixed $valeur, \Closure $echec) use ($estPassee): void {
                    $employe = Employe::query()->find($valeur);
                    if (! $employe?->estVeterinaire()) {
                        $echec('La garde revient à un vétérinaire.');
                    } elseif ($employe->acces_suspendu && ! $estPassee) {
                        // Une nuit passée nomme encore qui l'a faite, même parti depuis
                        $echec('Son accès est suspendu.');
                    } elseif ($this->estAbsent($employe)) {
                        $echec($employe->nom.' est absent ou hors contrat l’une de ces nuits.');
                    }
                },
            ],
        ];
    }

    /**
     * Les nuits que le choix touche : une correction, sa nuit seulement ;
     * une nuit à venir du week-end, le samedi et le dimanche (sans une
     * nuit passée).
     *
     * @return list<CarbonImmutable>
     */
    public function nuits(): array
    {
        $jour = DateSaisie::lire($this->input('jour'));
        if ($jour === null || $jour->lt(aujourdhui()->subDays(self::CORRECTION_JOURS))) {
            return [];
        }
        // Une correction du registre ne touche que sa nuit
        if ($jour->lt(aujourdhui())) {
            return [$jour];
        }
        $nuits = $jour->isWeekend()
            ? [$jour->startOfWeek(CarbonImmutable::MONDAY)->addDays(5), $jour->startOfWeek(CarbonImmutable::MONDAY)->addDays(6)]
            : [$jour];

        return array_values(array_filter($nuits, fn (CarbonImmutable $nuit): bool => $nuit->gte(aujourdhui())));
    }

    private function estAbsent(Employe $employe): bool
    {
        return collect($this->nuits())->contains(fn (CarbonImmutable $nuit): bool => ($employe->entree_le !== null && $employe->entree_le->gt($nuit))
            || ($employe->fin_contrat_le !== null && $employe->fin_contrat_le->lt($nuit))
            || Absence::query()
                ->where('employe_id', $employe->id)
                ->where('statut', StatutAbsence::Validee)
                ->where('type', '!=', TypeAbsence::Garde)
                ->whereDate('debut_le', '<=', $nuit)
                ->whereDate('fin_le', '>=', $nuit)
                ->exists());
    }
}
