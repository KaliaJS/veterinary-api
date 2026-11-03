<?php

namespace App\Services\Hospitalisation;

use App\Auth\ContexteClinique;
use App\Models\Clinique;
use App\Models\Sejour;
use App\Models\SejourSoin;
use App\Models\StaySheet;
use App\Models\StayTreatment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La feuille du jour d'un séjour (03.11.2026). Une feuille va de 06:00 à
 * 06:00 le lendemain et porte le nom du jour où elle s'ouvre : 02:00 du
 * mercredi appartient à la feuille du mardi. Les soins, douleurs et
 * observations gardent leur jour du calendrier et leur minute locale ; la
 * feuille s'en déduit ici, jamais au front.
 *
 * La feuille d'aujourd'hui s'ouvre d'elle-même à la première lecture, avec
 * les administrations des traitements en cours (une ligne par heure de
 * prise, jamais deux). Les feuilles passées se lisent sans se modifier ;
 * les jours à venir montrent les prises prévues sans rien écrire.
 */
final class StaySheets
{
    /** 06:00, en minutes : l'heure où la feuille change */
    public const int START = 360;

    /** Au plus, autant de feuilles manquantes ouvertes d'un coup (un séjour très long) */
    private const int BACKFILL_DAYS = 120;

    public function timezone(Sejour $sejour): string
    {
        $clinique = $sejour->relationLoaded('clinique') ? $sejour->clinique : Clinique::withoutGlobalScopes()->find($sejour->clinique_id);

        return $clinique?->fuseau_horaire ?: 'Europe/Zurich';
    }

    /** Le jour de la feuille d'un jour du calendrier et d'une minute locale */
    public static function sheetDayOf(string $day, int $minute): string
    {
        return $minute < self::START ? CarbonImmutable::parse($day)->subDay()->toDateString() : $day;
    }

    /**
     * L'instant (UTC) d'un jour du calendrier et d'une minute locale de la
     * clinique : l'heure murale, juste aussi les jours de changement d'heure
     * (25.10.2026 : 10:00 = 09:00 UTC ; 28.03.2027 : 10:00 = 08:00 UTC).
     */
    public function instant(Sejour $sejour, string $day, int $minute): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', $day.' '.StayRound::hm($minute), $this->timezone($sejour))->utc();
    }

    /** @return array{day: string, minute: int} le jour du calendrier et la minute locale d'un instant */
    public function local(Sejour $sejour, ?\DateTimeInterface $instant = null): array
    {
        $local = CarbonImmutable::instance($instant ?? now())->setTimezone($this->timezone($sejour));

        return ['day' => $local->toDateString(), 'minute' => $local->hour * 60 + $local->minute];
    }

    public function time(Sejour $sejour, ?\DateTimeInterface $instant): ?string
    {
        return $instant === null ? null : CarbonImmutable::instance($instant)->setTimezone($this->timezone($sejour))->format('H:i');
    }

    public function sheetDayAt(Sejour $sejour, \DateTimeInterface $instant): string
    {
        $local = $this->local($sejour, $instant);

        return self::sheetDayOf($local['day'], $local['minute']);
    }

    /** La feuille d'aujourd'hui (avant 06:00, encore celle de la veille) */
    public function today(Sejour $sejour): string
    {
        return $this->sheetDayAt($sejour, now());
    }

    /** La feuille d'aujourd'hui dans un fuseau (une clinique sans séjour) */
    public function todayIn(?string $timezone): string
    {
        $local = CarbonImmutable::now($timezone ?: 'Europe/Zurich');

        return self::sheetDayOf($local->toDateString(), $local->hour * 60 + $local->minute);
    }

    public function admissionDay(Sejour $sejour): string
    {
        return $this->sheetDayAt($sejour, $sejour->admis_le);
    }

    /** La dernière feuille du séjour : celle de la sortie, sinon aujourd'hui */
    public function lastDay(Sejour $sejour): string
    {
        return $sejour->sorti_le !== null ? $this->sheetDayAt($sejour, $sejour->sorti_le) : $this->today($sejour);
    }

    public function state(Sejour $sejour, string $sheetDay): string
    {
        $today = $this->today($sejour);

        return $sheetDay < $today || $sejour->sorti_le !== null ? 'closed' : ($sheetDay === $today ? 'open' : 'planned');
    }

    /** Jour 1 : la feuille de l'entrée */
    public function dayNumber(Sejour $sejour, ?string $sheetDay = null): int
    {
        return (int) CarbonImmutable::parse($this->admissionDay($sejour))->diffInDays(CarbonImmutable::parse($sheetDay ?? $this->lastDay($sejour))) + 1;
    }

    /** « depuis ce matin », « depuis hier », « depuis 3 jours » */
    public function sinceLabel(Sejour $sejour): string
    {
        $n = $this->dayNumber($sejour);
        if ($n >= 3) {
            return 'depuis '.$n.' jours';
        }
        if ($n === 2) {
            return 'depuis hier';
        }
        $heure = intdiv($this->local($sejour, $sejour->admis_le)['minute'], 60);

        return match (true) {
            $heure < 6 => 'depuis cette nuit',
            $heure < 12 => 'depuis ce matin',
            $heure < 18 => 'depuis cet après-midi',
            default => 'depuis ce soir',
        };
    }

    /**
     * Ouvre les feuilles manquantes, de l'entrée à aujourd'hui (une feuille
     * jamais ouverte, un jour où personne n'a regardé l'écran, montre aussi
     * ce qui était prévu et n'a pas été noté).
     */
    public function open(Sejour $sejour): void
    {
        if ($sejour->sorti_le !== null) {
            return;
        }
        $today = $this->today($sejour);
        $first = max($this->admissionDay($sejour), CarbonImmutable::parse($today)->subDays(self::BACKFILL_DAYS)->toDateString());
        $existing = StaySheet::query()->where('sejour_id', $sejour->id)->whereDate('day', '>=', $first)->pluck('day')
            ->map(fn ($day): string => CarbonImmutable::parse($day)->toDateString())->all();

        for ($day = CarbonImmutable::parse($first); $day->toDateString() <= $today; $day = $day->addDay()) {
            $key = $day->toDateString();
            if (in_array($key, $existing, true)) {
                continue;
            }
            // Une seule ouverture, même si deux postes lisent en même temps ;
            // la feuille et ses prises naissent ensemble ou pas du tout
            DB::transaction(function () use ($sejour, $key): void {
                $created = StaySheet::query()->insertOrIgnore([
                    'sejour_id' => $sejour->id,
                    // Au format des dates du modèle : la même clé unique que firstOrCreate
                    'day' => (new StaySheet)->fromDateTime(CarbonImmutable::parse($key)),
                    'opened_at' => now(),
                    'report_status' => StaySheet::REPORT_NONE,
                    'report_drafts' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($created === 1) {
                    $this->materialize($sejour, $key);
                }
            });
        }
    }

    /**
     * Les administrations des traitements en cours pour une feuille : une
     * par heure de prise, après le début du traitement, avant son arrêt,
     * pendant le séjour. Sans doublon (index unique).
     */
    public function materialize(Sejour $sejour, string $sheetDay, ?StayTreatment $only = null): void
    {
        $treatments = $only !== null ? collect([$only]) : StayTreatment::query()->where('sejour_id', $sejour->id)->get();
        foreach ($treatments as $treatment) {
            foreach ($this->doses($sejour, $treatment, $sheetDay) as $dose) {
                SejourSoin::query()->createOrFirst(
                    ['treatment_id' => $treatment->id, 'jour' => CarbonImmutable::parse($dose['day']), 'prevu_a' => $dose['minute']],
                    ['sejour_id' => $sejour->id, 'acte' => $treatment->label, 'dose' => $treatment->dose, 'dose_amount' => $treatment->dose_amount, 'produit_id' => $treatment->product_id],
                );
            }
        }
    }

    /**
     * Les prises d'un traitement sur une feuille.
     *
     * @return list<array{day: string, minute: int}>
     */
    public function doses(Sejour $sejour, StayTreatment $treatment, string $sheetDay): array
    {
        $doses = [];
        $next = CarbonImmutable::parse($sheetDay)->addDay()->toDateString();
        foreach ($treatment->minutes() as $minute) {
            $day = $minute < self::START ? $next : $sheetDay;
            $at = $this->instant($sejour, $day, $minute);
            if ($at->lt($treatment->starts_at) || ($treatment->ends_at !== null && $at->gte($treatment->ends_at))
                || $at->lt($sejour->admis_le) || ($sejour->sorti_le !== null && $at->gt($sejour->sorti_le))) {
                continue;
            }
            $doses[] = ['day' => $day, 'minute' => $minute, 'order' => $minute < self::START ? $minute + 1440 : $minute];
        }
        usort($doses, fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_map(fn (array $d): array => ['day' => $d['day'], 'minute' => $d['minute']], $doses);
    }

    /**
     * Les prises prévues des jours à venir, entre deux feuilles (rien n'est
     * écrit) ; aujourd'hui et avant, elles sont dans le plan de soins.
     *
     * @param  iterable<StayTreatment>  $treatments
     * @return list<array<string, mixed>>
     */
    public function planned(Sejour $sejour, iterable $treatments, string $from, string $to): array
    {
        if ($sejour->sorti_le !== null) {
            return [];
        }
        $start = max($from, CarbonImmutable::parse($this->today($sejour))->addDay()->toDateString());
        $planned = [];
        for ($day = CarbonImmutable::parse($start); $day->toDateString() <= $to; $day = $day->addDay()) {
            foreach ($treatments as $treatment) {
                foreach ($this->doses($sejour, $treatment, $day->toDateString()) as $dose) {
                    $planned[] = [
                        'day' => $dose['day'],
                        'minute' => $dose['minute'],
                        'sheet_day' => $day->toDateString(),
                        'treatment_id' => $treatment->id,
                        'label' => $treatment->label,
                        'dose' => $treatment->dose,
                        'dose_amount' => $treatment->dose_amount,
                        'product' => $treatment->product === null ? null : ['id' => $treatment->product->id, 'name' => $treatment->product->nom],
                    ];
                }
            }
        }
        usort($planned, fn (array $a, array $b): int => [$a['day'], $a['minute']] <=> [$b['day'], $b['minute']]);

        return $planned;
    }

    /**
     * L'heure réelle d'un geste (03.11.2026) : celle choisie par la personne
     * (jour de la feuille et minute ; avant 06:00, le lendemain au
     * calendrier), sinon maintenant. Sur une feuille close, l'heure est
     * demandée et le geste est une note tardive (décision de Jim : sans
     * limite, toujours marquée), après la sortie aussi, à une heure du
     * séjour. Jamais à venir, jamais avant l'entrée.
     *
     * @return array{day: string, minute: int, sheet_day: string, at: CarbonImmutable, late: bool}
     */
    public function gestureTime(Sejour $sejour, ?string $sheetDay, ?int $minute, bool $isRequired = false, string $key = 'time'): array
    {
        $today = $this->today($sejour);
        $isDischarged = $sejour->sorti_le !== null;
        if ($minute === null) {
            // Après la sortie, une note se rattache au séjour par son heure
            if ($isRequired || $isDischarged || ($sheetDay !== null && $sheetDay < $today)) {
                throw ValidationException::withMessages([$key => 'Sur une feuille close, dites à quelle heure le geste a été fait.']);
            }
            $now = $this->local($sejour);

            return [...$now, 'sheet_day' => self::sheetDayOf($now['day'], $now['minute']), 'at' => CarbonImmutable::now()->utc(), 'late' => false];
        }
        $sheetDay ??= $today;
        $day = $minute < self::START ? CarbonImmutable::parse($sheetDay)->addDay()->toDateString() : $sheetDay;
        $at = $this->instant($sejour, $day, $minute);
        if ($at->gt(now()->addMinute())) {
            throw ValidationException::withMessages([$key => 'Cette heure est à venir : le geste se note quand il est fait.']);
        }
        if ($at->lt(CarbonImmutable::instance($sejour->admis_le)->startOfMinute())) {
            throw ValidationException::withMessages([$key => 'L’animal n’était pas encore là à cette heure.']);
        }
        if ($isDischarged && $at->gt($sejour->sorti_le)) {
            throw ValidationException::withMessages([$key => 'L’animal était déjà sorti à cette heure.']);
        }

        return ['day' => $day, 'minute' => $minute, 'sheet_day' => $sheetDay, 'at' => $at, 'late' => $sheetDay < $today || $isDischarged];
    }

    /** « Noté après coup, le 3.11 à 14:05, par Julie Muller » */
    public function lateLabel(Sejour $sejour, ?\DateTimeInterface $notedAt, ?string $by): ?string
    {
        if ($notedAt === null) {
            return null;
        }
        $local = CarbonImmutable::instance($notedAt)->setTimezone($this->timezone($sejour));

        return 'Noté après coup, le '.$local->format('j.n').' à '.$local->format('H:i').($by ? ', par '.$by : '');
    }

    /** Le nom de qui fait le geste, tel qu'aujourd'hui (gardé avec le geste) */
    public function authorName(?User $user): ?string
    {
        return app(ContexteClinique::class)->employe()->nom ?? $user?->name;
    }
}
