<?php

namespace App\Models;

use App\Enums\Espece;
use App\Models\Concerns\AppartientAUneOrganisation;
use App\Support\Medvet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une convention Médvét (OMédV art. 10, annexe 1, 09.11.2026) : une par
 * espèce et par exploitation, d'au moins un an, avec la catégorie de
 * l'exploitation qui donne ses visites minimales par an.
 */
class MedvetAgreement extends Model
{
    use AppartientAUneOrganisation;

    protected $fillable = ['organisation_id', 'clinique_id', 'client_id', 'species', 'category', 'starts_on', 'ends_on', 'vet_name', 'terminated_on', 'created_by_id'];

    protected function casts(): array
    {
        return ['species' => Espece::class, 'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'terminated_on' => 'date:Y-m-d'];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return HasMany<FarmVisit, $this> */
    public function visits(): HasMany
    {
        return $this->hasMany(FarmVisit::class);
    }

    /**
     * Le dernier jour de la convention : sa fin, ou la veille de sa
     * résiliation (résiliée ce jour, elle ne l'est déjà plus ; une autre
     * peut commencer le même jour).
     */
    public function lastDay(): CarbonImmutable
    {
        $fin = CarbonImmutable::parse($this->ends_on);
        if ($this->terminated_on !== null) {
            $veille = CarbonImmutable::parse($this->terminated_on)->subDay();

            return $veille->lt($fin) ? $veille : $fin;
        }

        return $fin;
    }

    /** En cours ce jour : commencée, pas finie ni résiliée */
    public function isActiveOn(string $day): bool
    {
        return $this->starts_on->toDateString() <= $day && $day <= $this->lastDay()->toDateString();
    }

    public function requiredVisits(): int
    {
        return Medvet::CATEGORIES[$this->category]['visits'] ?? 1;
    }

    /**
     * L'année de convention en cours (d'anniversaire en anniversaire, bornée
     * à la fin de la convention), les visites faites, celles attendues à ce
     * jour (réparties sur l'année : la i-e avant la fin de sa part d'année),
     * et le retard : moins que celles attendues, ou il en manque encore
     * alors que moins de 60 jours restent (une catégorie à une visite par
     * an est ainsi prévenue avant la fin). L'année passée, si des visites y
     * ont manqué, est dite aussi (vérification du 10.11.2026).
     *
     * @return array{from: string, to: string, done: int, required: int, expected: int, days_left: int, behind: bool, previous: array{from: string, to: string, done: int, required: int, missed: bool}|null}
     */
    public function yearStatus(string $today): array
    {
        $debut = CarbonImmutable::parse($this->starts_on);
        $dernier = $this->lastDay();
        $jour = CarbonImmutable::parse(min($today, $dernier->toDateString()));
        // L'année k va du début plus k ans au début plus k+1 ans, moins un jour (sans débordement : un 29 février donne le 28)
        $annees = 0;
        while ($debut->addYearsNoOverflow($annees + 1)->lte($jour)) {
            $annees++;
        }
        $de = $debut->addYearsNoOverflow($annees);
        $a = $debut->addYearsNoOverflow($annees + 1)->subDay();
        if ($a->gt($dernier)) {
            $a = $dernier;
        }
        $requises = $this->requiredVisits();
        $faites = $this->visitsBetween($de, $a);
        $duree = max(1, $de->diffInDays($a) + 1);
        $ecoule = min(1, max(0, $de->diffInDays($jour) + 1) / $duree);
        $attendues = (int) floor($requises * $ecoule);
        $restants = max(0, (int) $jour->diffInDays($a));
        $enRetard = $faites < $attendues || ($faites < $requises && $restants <= 60);

        $precedente = null;
        if ($annees > 0) {
            $pde = $debut->addYearsNoOverflow($annees - 1);
            $pa = $de->subDay();
            $pfaites = $this->visitsBetween($pde, $pa);
            $precedente = ['from' => $pde->toDateString(), 'to' => $pa->toDateString(), 'done' => $pfaites, 'required' => $requises, 'missed' => $pfaites < $requises];
        }

        return [
            'from' => $de->toDateString(), 'to' => $a->toDateString(), 'done' => $faites, 'required' => $requises,
            'expected' => $attendues, 'days_left' => $restants, 'behind' => $enRetard, 'previous' => $precedente,
        ];
    }

    /** Les visites de la convention entre deux jours compris (la relation chargée, sinon une requête) */
    private function visitsBetween(CarbonImmutable $de, CarbonImmutable $a): int
    {
        if ($this->relationLoaded('visits')) {
            return $this->visits->filter(fn (FarmVisit $v): bool => $v->visited_on->toDateString() >= $de->toDateString() && $v->visited_on->toDateString() <= $a->toDateString())->count();
        }

        return $this->visits()->whereBetween('visited_on', [$de->toDateString(), $a->toDateString()])->count();
    }
}
