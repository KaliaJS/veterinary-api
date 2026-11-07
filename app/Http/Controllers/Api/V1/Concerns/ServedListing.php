<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Les listes triées, filtrées et cherchées par le serveur (tri au-delà de
 * 100 lignes, 07.11.2026) : le tri et les filtres d'une liste blanche (une
 * valeur inconnue est refusée, 422), une recherche insensible à la casse
 * qui marche sous MySQL comme sous SQLite (`lower(…) like … escape '!'` ;
 * les jokers tapés restent des lettres).
 */
trait ServedListing
{
    /** Le plus long texte cherché */
    public const int SEARCH_MAX = 100;

    /**
     * Les règles de `tri` et de `q`.
     *
     * @param  list<string>  $sorts
     * @return array<string, mixed>
     */
    protected function listingRules(array $sorts): array
    {
        return [
            'tri' => ['sometimes', 'nullable', 'string', Rule::in($sorts)],
            'q' => ['sometimes', 'nullable', 'string', 'max:'.self::SEARCH_MAX],
        ];
    }

    /** Le tri demandé, ou celui par défaut */
    protected function sortOf(Request $request, string $default): string
    {
        return $request->filled('tri') ? (string) $request->string('tri') : $default;
    }

    /** Le motif `like` de la recherche, en minuscules, les jokers échappés ; null sans recherche */
    protected function searchPattern(Request $request): ?string
    {
        $q = trim((string) $request->string('q'));

        return $q === '' ? null : '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($q)).'%';
    }

    /**
     * Une des expressions contient le motif : `lower(e) like motif`, les
     * expressions en « ou », le groupe ajouté en `$boolean` (« et » par défaut).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $expressions
     * @return Builder<TModel>
     */
    protected function whereLikeAny(Builder $query, array $expressions, string $pattern, string $boolean = 'and'): Builder
    {
        return $query->where(function (Builder $clause) use ($expressions, $pattern): void {
            foreach ($expressions as $expression) {
                $clause->whereRaw("lower({$expression}) like ? escape '!'", [$pattern], 'or');
            }
        }, null, null, $boolean);
    }
}
