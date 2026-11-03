<?php

namespace App\Services\Hospitalisation;

use App\Auth\ContexteClinique;
use App\Enums\PainScale;
use App\Enums\PushType;
use App\Enums\StatutSejour;
use App\Events\MessagesChangent;
use App\Jobs\DraftDailyReport;
use App\Mail\ReponseClinique;
use App\Models\Client;
use App\Models\MessageClientele;
use App\Models\PreferencesProprietaire;
use App\Models\Sejour;
use App\Models\SejourConstante;
use App\Models\SejourSoin;
use App\Models\StayObservation;
use App\Models\StayPainScore;
use App\Models\StaySheet;
use App\Models\User;
use App\Services\BudgetAnthropic;
use App\Services\CompteurMensuel;
use App\Services\Notifications\Push;
use App\Support\TempsReel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Le rapport de la journée d'un animal hospitalisé (03.11.2026) : rédigé
 * par l'IA depuis la feuille, par la même infrastructure que les comptes
 * rendus (file d'attente, budget mensuel d'Anthropic, compteur du mois,
 * nombre de rédactions borné). Il attend la relecture (« à relire »), puis
 * la signature d'un membre de l'équipe (nom figé), puis peut partir au
 * propriétaire par sa messagerie. Sans clé ou sans budget, il le dit.
 */
final class DailyReports
{
    /** Pourquoi l'IA ne peut plus rédiger : ce que fait l'équipe à la place */
    private const array BLOCKED = [
        'not_configured' => 'L’IA n’est pas configurée sur ce serveur : écrivez le rapport à la main.',
        'drafts' => 'Cinq rédactions déjà faites pour cette journée : corrigez le texte à la main.',
        'budget' => 'Le budget de l’IA de ce mois est atteint : écrivez le rapport à la main.',
    ];

    /** Pourquoi le rapport n'a pas pu être rédigé, dit à l'équipe */
    private const array ERRORS = [
        'not_configured' => 'l’IA n’est pas configurée sur ce serveur.',
        'budget' => 'le budget de l’IA de ce mois est atteint.',
        'drafts' => 'trop de rédactions pour cette journée.',
        'writing' => 'l’IA n’a pas répondu. Réessayez plus tard.',
        'empty' => 'rien n’est noté sur la feuille de ce jour.',
    ];

    public function __construct(private StaySheets $sheets, private StayRound $round) {}

    /** La feuille d'un jour du séjour, jusqu'à aujourd'hui (ouverte au besoin) */
    public function sheet(Sejour $sejour, string $day): StaySheet
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || $day < $this->sheets->admissionDay($sejour) || $day > $this->sheets->lastDay($sejour)) {
            throw ValidationException::withMessages(['day' => 'Pas de rapport pour ce jour : l’animal n’était pas là, ou la journée n’a pas commencé.']);
        }
        $this->sheets->open($sejour);

        return StaySheet::query()->where('sejour_id', $sejour->id)->whereDate('day', $day)->first()
            ?? StaySheet::query()->create(['sejour_id' => $sejour->id, 'day' => $day, 'opened_at' => now()]);
    }

    /** Demande la rédaction : refusée, elle le dit sur la feuille ; sinon, elle part en file */
    public function request(Sejour $sejour, StaySheet $sheet): void
    {
        if (in_array($sheet->report_status, [StaySheet::REPORT_SIGNED, StaySheet::REPORT_DRAFTING], true)) {
            throw ValidationException::withMessages(['report' => $sheet->report_status === StaySheet::REPORT_SIGNED ? 'Ce rapport est signé : il ne se rédige plus.' : 'Le rapport est en cours de rédaction.']);
        }

        $refusal = $this->blocked($sheet) ?? ($this->isEmpty($sejour, $sheet->day->toDateString()) ? 'empty' : null);
        if ($refusal !== null) {
            // Un texte déjà là reste à relire, corrigeable et signable (03.11.2026)
            $sheet->forceFill([
                'report_status' => blank($sheet->report_text) ? StaySheet::REPORT_FAILED : StaySheet::REPORT_TO_REVIEW,
                'report_error' => $refusal,
            ])->save();
            Sejour::announce($sejour->id, $sejour->clinique_id);

            return;
        }

        $taken = StaySheet::query()->whereKey($sheet->id)->where('report_status', $sheet->report_status)
            ->update(['report_status' => StaySheet::REPORT_DRAFTING, 'report_error' => null, 'report_drafts' => $sheet->report_drafts + 1, 'updated_at' => now()]);
        if ($taken !== 1) {
            return;
        }
        // Compté au budget du mois où il part, comme les comptes rendus
        CompteurMensuel::ajouter('anthropic:rapports', 1);
        Sejour::announce($sejour->id, $sejour->clinique_id);
        DraftDailyReport::dispatch($sheet->id);
    }

    /**
     * Corriger le texte, ou l'écrire à la main sans IA (03.11.2026) : tant
     * qu'il n'est ni signé ni en cours de rédaction. La feuille est relue
     * sous verrou : une correction arrivée après la signature n'est jamais
     * enregistrée (deuxième vérification).
     */
    public function edit(Sejour $sejour, StaySheet $sheet, string $text): void
    {
        DB::transaction(function () use ($sheet, $text): void {
            $fresh = StaySheet::query()->whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            if ($fresh->report_status === StaySheet::REPORT_SIGNED) {
                throw ValidationException::withMessages(['text' => $sheet->report_status === StaySheet::REPORT_SIGNED
                    ? 'Ce rapport est signé : il ne change plus. Notez l’oubli après coup sur la feuille.'
                    : 'Ce rapport vient d’être signé : la correction n’est pas enregistrée. Notez-la après coup sur la feuille.']);
            }
            if ($fresh->report_status === StaySheet::REPORT_DRAFTING) {
                throw ValidationException::withMessages(['text' => 'Le rapport est en cours de rédaction.']);
            }
            $fresh->forceFill([
                'report_text' => trim($text),
                'report_status' => StaySheet::REPORT_TO_REVIEW,
                'report_source' => $fresh->report_status === StaySheet::REPORT_TO_REVIEW && $fresh->report_source !== null ? $fresh->report_source : 'manual',
            ])->save();
        });
        Sejour::announce($sejour->id, $sejour->clinique_id);
    }

    /**
     * On signe le texte enregistré, celui qu'on a sous les yeux : pas un
     * autre (03.11.2026). Vérifié sous verrou : une correction passée
     * entre-temps fait refuser la signature.
     */
    public function sign(Sejour $sejour, StaySheet $sheet, ?User $user, string $text): void
    {
        DB::transaction(function () use ($sheet, $user, $text): void {
            $fresh = StaySheet::query()->whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            if ($fresh->report_status !== StaySheet::REPORT_TO_REVIEW || blank($fresh->report_text)) {
                throw ValidationException::withMessages(['report' => 'Seul un rapport rédigé et à relire se signe.']);
            }
            if (trim($text) !== trim((string) $fresh->report_text)) {
                throw ValidationException::withMessages(['text' => 'Le texte a changé depuis le dernier enregistrement : enregistrez-le avant de signer.']);
            }
            $fresh->forceFill([
                'report_status' => StaySheet::REPORT_SIGNED,
                'report_signed_at' => now(),
                'report_signed_by_id' => $user?->id,
                'report_signed_by_name' => $this->sheets->authorName($user),
            ])->save();
        });
        Sejour::announce($sejour->id, $sejour->clinique_id);
    }

    /** Le rapport signé part au propriétaire, dans sa messagerie (et par e-mail s'il ne l'a pas coupé) */
    public function send(Sejour $sejour, StaySheet $sheet, ?User $user): void
    {
        $client = $this->owner($sejour);
        if ($client?->user_id === null) {
            throw ValidationException::withMessages(['report' => 'Ce propriétaire n’a pas de compte : il ne pourrait pas lire le rapport. Appelez-le.']);
        }
        $contexte = app(ContexteClinique::class);

        // Sous verrou : deux clics, deux postes, un seul envoi (03.11.2026)
        [$message, $text, $first] = DB::transaction(function () use ($sejour, $sheet, $client, $contexte): array {
            $sheet = StaySheet::query()->whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            if ($sheet->report_status !== StaySheet::REPORT_SIGNED) {
                throw ValidationException::withMessages(['report' => 'Le rapport part au propriétaire une fois relu et signé.']);
            }
            if ($sheet->report_sent_at !== null) {
                throw ValidationException::withMessages(['report' => 'Ce rapport a déjà été envoyé au propriétaire.']);
            }
            $day = CarbonImmutable::parse($sheet->day->toDateString())->locale('fr_CH')->isoFormat('dddd D MMMM');
            $text = 'Rapport de la journée du '.$day.' pour '.$sejour->patient->nom.', relu et signé par '.$sheet->report_signed_by_name." :\n\n".$sheet->report_text;
            // Ce qui a changé après la signature part avec (troisième vérification)
            $additions = $this->lateAdditions($sejour, $sheet->day->toDateString(), $sheet->report_signed_at);
            if ($additions !== []) {
                $text .= "\n\nAjouté après la signature :\n".implode("\n", array_map(fn (array $a): string => '• '.$a['label'], $additions));
            }
            $first = ! MessageClientele::query()->where('clinique_id', $sejour->clinique_id)->where('user_id', $client->user_id)->exists();
            $message = MessageClientele::create([
                'clinique_id' => $sejour->clinique_id,
                'user_id' => $client->user_id,
                'client_id' => $client->id,
                'employe_id' => $contexte->employe()?->id,
                'auteur' => 'clinique',
                'texte' => $text,
            ]);
            $sheet->forceFill(['report_sent_at' => now()])->save();

            return [$message, $text, $first];
        });

        $userId = (int) $client->user_id;
        TempsReel::diffuser(fn () => MessagesChangent::dispatch('utilisateur.'.$userId, $userId));
        TempsReel::diffuser(fn () => MessagesChangent::dispatch('clinique.'.$sejour->clinique_id, $userId));
        Push::to([$userId], PushType::ClinicMessage, (string) $contexte->clinique()?->nom, mb_strimwidth($text, 0, 120, '…'), '/espace/messages');
        if ($client->user !== null && PreferencesProprietaire::query()->where('user_id', $userId)->value('reponses_clinique') !== false) {
            try {
                Mail::to($client->user->email)->send(new ReponseClinique($message, $contexte->clinique(), $first));
            } catch (Throwable $e) {
                report($e);
            }
        }
        Sejour::announce($sejour->id, $sejour->clinique_id);
    }

    /**
     * Le rapport d'un jour tel que l'écran le montre.
     *
     * @return array<string, mixed>
     */
    public function toArray(Sejour $sejour, ?StaySheet $sheet, string $day): array
    {
        $status = $sheet->report_status ?? StaySheet::REPORT_NONE;
        $state = $this->sheets->state($sejour, $day);
        $signedTime = $this->sheets->time($sejour, $sheet?->report_signed_at);
        $signedOtherDay = $sheet?->report_signed_at !== null && $this->sheets->local($sejour, $sheet->report_signed_at)['day'] !== $day;

        $label = match ($status) {
            StaySheet::REPORT_DRAFTING => 'L’IA rédige le rapport depuis la feuille',
            StaySheet::REPORT_TO_REVIEW => $sheet?->report_source === 'manual' ? 'Écrit par l’équipe, à relire et signer' : 'Rédigé par l’IA depuis la feuille, à relire',
            StaySheet::REPORT_SIGNED => 'Relu et signé '.($signedOtherDay ? 'le '.CarbonImmutable::instance($sheet->report_signed_at)->setTimezone($this->sheets->timezone($sejour))->format('j.n').' ' : '').'à '.$signedTime.' par '.$sheet?->report_signed_by_name,
            StaySheet::REPORT_FAILED => 'Le rapport n’a pas pu être rédigé : '.(self::ERRORS[$sheet?->report_error] ?? self::ERRORS['writing']),
            default => $state === 'planned' ? 'Pas encore de rapport : il se rédige le jour même' : 'Pas encore de rapport pour ce jour',
        };
        $hasAccount = $this->owner($sejour)?->user_id !== null;
        $blocked = $sheet !== null ? $this->blocked($sheet) : $this->blocked(new StaySheet);
        $isOpenForText = $state !== 'planned' && in_array($status, [StaySheet::REPORT_NONE, StaySheet::REPORT_TO_REVIEW, StaySheet::REPORT_FAILED], true);

        return [
            'status' => $status,
            'status_label' => $label,
            'text' => $sheet?->report_text,
            'signed_by' => $sheet?->report_signed_by_name,
            'signed_time' => $signedTime,
            'is_sent' => $sheet?->report_sent_at !== null,
            'sent_label' => $sheet?->report_sent_at !== null ? 'Envoyé au propriétaire à '.$this->sheets->time($sejour, $sheet->report_sent_at) : null,
            'source' => $sheet?->report_source,
            // L'IA ne peut plus réussir : « Refaire » se cache et l'écran dit pourquoi
            'can_draft' => $isOpenForText && $blocked === null,
            'draft_blocked_reason' => $isOpenForText && $blocked !== null ? self::BLOCKED[$blocked] : null,
            'notice' => $status === StaySheet::REPORT_TO_REVIEW && $sheet?->report_error !== null ? 'Nouvelle rédaction impossible : '.(self::ERRORS[$sheet->report_error] ?? self::ERRORS['writing']) : null,
            // Corriger, ou écrire à la main sans IA, puis signer (03.11.2026)
            'can_edit' => $isOpenForText,
            'can_sign' => $status === StaySheet::REPORT_TO_REVIEW && ! blank($sheet?->report_text),
            'can_send' => $status === StaySheet::REPORT_SIGNED && $sheet?->report_sent_at === null && $hasAccount,
            'owner_has_account' => $hasAccount,
            // Les notes tardives après la signature : à côté du rapport, qui ne change pas
            'late_additions' => $status === StaySheet::REPORT_SIGNED ? $this->lateAdditions($sejour, $day, $sheet->report_signed_at) : [],
        ];
    }

    /** Pourquoi l'IA ne peut pas rédiger maintenant (null : elle le peut) */
    private function blocked(StaySheet $sheet): ?string
    {
        return match (true) {
            blank(config('services.anthropic.key')) => 'not_configured',
            $sheet->report_drafts >= (int) config('services.anthropic.rapports_max', 5) => 'drafts',
            app(BudgetAnthropic::class)->atteint() => 'budget',
            default => null,
        };
    }

    /**
     * Tout ce qui a changé la feuille après la signature de son rapport
     * (troisième vérification, 03.11.2026) : prise annulée, prise donnée ou
     * non donnée, observation, douleur, constantes ; le jour même aussi. Le
     * libellé dit ce qui s'est passé, et si c'était après coup.
     *
     * @return list<array{label: string, late_label: ?string}>
     */
    public function lateAdditions(Sejour $sejour, string $day, ?\DateTimeInterface $signedAt): array
    {
        if ($signedAt === null) {
            return [];
        }
        $isAfter = fn (?\DateTimeInterface $at): bool => $at !== null && $at > $signedAt;
        $tz = $this->sheets->timezone($sejour);
        // Une seule fois « après coup », avec le jour et l'heure de la note (quatrième vérification)
        $by = function (bool $late, ?string $name, string $verb = 'noté', ?\DateTimeInterface $noted = null) use ($tz): string {
            if (! $late) {
                return ', '.$verb.' par '.$name;
            }
            $at = $noted !== null ? CarbonImmutable::instance($noted)->setTimezone($tz) : null;

            return ', '.$verb.' après coup'.($at !== null ? ' le '.$at->format('j.n').' à '.$at->format('H:i') : '').' par '.$name;
        };
        $items = [];

        foreach (SejourSoin::query()->where('sejour_id', $sejour->id)->with(['treatment', 'cancellations'])->get() as $soin) {
            $care = $this->round->care($sejour, $soin);
            if ($care['sheet_day'] !== $day) {
                continue;
            }
            $planned = StayRound::hm($soin->prevu_a);
            $verbGiven = $care['is_medication'] ? ' donné à ' : ' fait à ';
            // Chaque fois qu'elle a été donnée puis annulée, même notée de nouveau depuis (sixième vérification)
            foreach ($soin->cancellations as $c) {
                if ($isAfter($noted = $c->late_noted_at ?? $c->given_at)) {
                    $items[] = ['label' => $soin->acte.$verbGiven.$this->sheets->time($sejour, $c->given_at).$by($c->late_noted_at !== null, $c->given_by_name, 'noté', $c->late_noted_at), 'late_label' => null, 'at' => $noted];
                }
                if ($isAfter($c->cancelled_at)) {
                    $at = CarbonImmutable::instance($c->cancelled_at)->setTimezone($tz);
                    $items[] = ['label' => $soin->acte.' de '.$planned.' annulé le '.$at->format('j.n').' à '.$at->format('H:i').' par '.$c->cancelled_by_name, 'late_label' => null, 'at' => $c->cancelled_at];
                }
            }
            if ($soin->cancelled_at !== null) {
                continue;
            }
            $late = $soin->late_noted_at !== null;
            if ($soin->fait && $isAfter($noted = $soin->late_noted_at ?? $soin->updated_at)) {
                $verb = $care['is_medication'] ? ' donné à ' : ' fait à ';
                $items[] = ['label' => $soin->acte.$verb.$this->sheets->time($sejour, $soin->fait_le).$by($late, $soin->done_by_name, 'noté', $soin->late_noted_at), 'late_label' => null, 'at' => $noted];
            } elseif ($soin->skipped_at !== null && $isAfter($noted = $soin->late_noted_at ?? $soin->updated_at)) {
                $verb = $care['is_medication'] ? ' non donné' : ' non fait';
                $items[] = ['label' => $soin->acte.' de '.$planned.$verb.$by($late, $soin->skipped_by_name, 'noté', $soin->late_noted_at).($soin->skip_reason ? ' : '.$soin->skip_reason : ''), 'late_label' => null, 'at' => $noted];
            }
        }
        foreach (StayPainScore::query()->where('sejour_id', $sejour->id)->get() as $pain) {
            $p = $this->round->pain($pain, $sejour);
            if ($p['sheet_day'] === $day && $isAfter($pain->created_at)) {
                $items[] = ['label' => 'Douleur '.$p['score'].' sur '.$p['max'].' à '.$p['time'].$by($pain->late_noted_at !== null, $p['by'], 'évaluée', $pain->late_noted_at), 'late_label' => null, 'at' => $pain->created_at];
            }
        }
        foreach (StayObservation::query()->where('sejour_id', $sejour->id)->get() as $obs) {
            $o = $this->round->observation($obs, $sejour);
            if ($o['sheet_day'] === $day && $isAfter($obs->created_at)) {
                $items[] = ['label' => $o['label'].' : '.$o['value'].' à '.$o['time'].$by($obs->late_noted_at !== null, $o['by'], 'noté', $obs->late_noted_at), 'late_label' => null, 'at' => $obs->created_at];
            }
        }
        foreach (SejourConstante::query()->where('sejour_id', $sejour->id)->get() as $c) {
            if (StaySheets::sheetDayOf($c->jour->toDateString(), $c->mesure_a) === $day && $isAfter($c->created_at)) {
                $items[] = ['label' => 'Constantes à '.StayRound::hm($c->mesure_a).' : T° '.str_replace('.', ',', (string) $c->temperature).' °C, FC '.$c->fc.', FR '.$c->fr.$by($c->late_noted_at !== null, $c->by_name, 'relevées', $c->late_noted_at), 'late_label' => null, 'at' => $c->created_at];
            }
        }
        usort($items, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return array_map(fn (array $i): array => ['label' => $i['label'], 'late_label' => $i['late_label']], $items);
    }

    /** Le propriétaire qui a confié l'animal, figé à l'admission (décision de Jim, 03.11.2026) */
    private function owner(Sejour $sejour): ?Client
    {
        return $sejour->client_id !== null ? Client::query()->find($sejour->client_id) : $sejour->patient?->client;
    }

    private function isEmpty(Sejour $sejour, string $day): bool
    {
        $round = $this->round->range($sejour, $day, $day);
        $constants = $this->constants($sejour, $day);

        return $round['cares'] === [] && $round['pains'] === [] && $round['observations'] === [] && $constants === [];
    }

    /** @return list<array{time: string, temperature: string, fc: int, fr: int}> */
    private function constants(Sejour $sejour, string $day): array
    {
        $next = CarbonImmutable::parse($day)->addDay()->toDateString();

        return SejourConstante::query()->where('sejour_id', $sejour->id)->whereDate('jour', '>=', $day)->whereDate('jour', '<=', $next)
            ->orderBy('jour')->orderBy('mesure_a')->get()
            ->filter(fn (SejourConstante $c): bool => StaySheets::sheetDayOf($c->jour->toDateString(), $c->mesure_a) === $day)
            ->map(fn (SejourConstante $c): array => [
                'time' => StayRound::hm($c->mesure_a),
                'temperature' => str_replace('.', ',', (string) $c->temperature),
                'fc' => $c->fc,
                'fr' => $c->fr,
            ])->values()->all();
    }

    /**
     * La feuille du jour, ligne par ligne, telle qu'on la donne à l'IA :
     * seulement ce qui est écrit (sans le nom du propriétaire).
     */
    public function facts(Sejour $sejour, string $day): string
    {
        $round = $this->round->range($sejour, $day, $day);
        $patient = $sejour->patient;
        $scale = PainScale::forSpecies($patient?->espece);
        $long = CarbonImmutable::parse($day)->locale('fr_CH')->isoFormat('dddd D MMMM YYYY');
        $lines = [
            'Animal : '.($patient->nom ?? 'inconnu').($patient?->espece ? ', '.$patient->espece->libelle() : ''),
            'Motif de l’hospitalisation : '.$sejour->motif,
            'Jour '.$this->sheets->dayNumber($sejour, $day).' du séjour, feuille du '.$long.' (de 06:00 à 06:00 le lendemain)',
            'État : '.match ($sejour->statut) {
                StatutSejour::Critique => 'critique',
                StatutSejour::Surveillance => 'sous surveillance',
                default => 'stable',
            },
        ];
        if ($sejour->perfusion) {
            $lines[] = 'Perfusion : '.$sejour->perfusion;
        }

        $state = fn (array $c, string $done, string $skipped): string => match ($c['status']) {
            'done' => $done.' à '.$c['done_time'].' par '.($c['by'] ?? 'un membre de l’équipe').($c['previous_label'] ? ' (une prise notée avant par erreur a été annulée)' : ''),
            // Donnée par erreur puis annulée (sixième vérification) : ni donnée, ni oubliée
            'cancelled' => 'notée par erreur puis annulée ('.$c['cancelled_label'].'), pas donnée',
            'skipped' => $skipped.' (noté à '.$c['done_time'].' par '.($c['by'] ?? 'un membre de l’équipe').($c['reason'] ? ', raison : '.$c['reason'] : '').')',
            default => $this->sheets->instant($sejour, $c['day'], $c['minute'])->isFuture() ? 'à venir, pas encore l’heure' : 'pas noté',
        };
        $meds = collect($round['cares'])->where('is_medication', true);
        $lines[] = '';
        $lines[] = 'Médicaments :';
        foreach ($meds as $m) {
            // La dose donnée, dans l'unité figée sur la prise (sixième vérification)
            $amount = $m['status'] === 'done' && $m['dose_amount'] ? ', '.str_replace('.', ',', (string) $m['dose_amount']).' '.$m['dose_unit'] : '';
            $lines[] = '- prévu à '.StayRound::hm($m['minute']).' : '.$m['label'].($m['dose'] ? ' '.$m['dose'] : '').$amount.', '.$state($m, 'donné', 'non donné');
        }
        if ($meds->isEmpty()) {
            $lines[] = '- aucun';
        }
        $cares = collect($round['cares'])->where('is_medication', false);
        $lines[] = '';
        $lines[] = 'Soins :';
        foreach ($cares as $c) {
            $lines[] = '- prévu à '.StayRound::hm($c['minute']).' : '.$c['label'].', '.$state($c, 'fait', 'non fait');
        }
        if ($cares->isEmpty()) {
            $lines[] = '- aucun';
        }
        $lines[] = '';
        $lines[] = 'Douleur ('.$scale->label().', seuil de traitement '.$scale->threshold().' sur '.$scale->max().') :';
        foreach ($round['pains'] as $p) {
            $lines[] = '- '.$p['time'].' : '.$p['score'].' sur '.$p['max'].($p['is_above'] ? ' (au-dessus du seuil de traitement)' : ' (sous le seuil)').', évaluée par '.($p['by'] ?? 'un membre de l’équipe').($p['note'] ? ', note : '.$p['note'] : '');
        }
        if ($round['pains'] === []) {
            $lines[] = '- non évaluée';
        }
        $lines[] = '';
        $lines[] = 'Repas et éliminations :';
        foreach ($round['observations'] as $o) {
            $lines[] = '- '.$o['time'].' '.$o['label'].' : '.$o['value'].', noté par '.($o['by'] ?? 'un membre de l’équipe');
        }
        if ($round['observations'] === []) {
            $lines[] = '- rien de noté';
        }
        $constants = $this->constants($sejour, $day);
        if ($constants !== []) {
            $lines[] = '';
            $lines[] = 'Constantes :';
            foreach ($constants as $c) {
                $lines[] = '- '.$c['time'].' : T° '.$c['temperature'].' °C, FC '.$c['fc'].', FR '.$c['fr'];
            }
        }
        if ($sejour->note) {
            $lines[] = '';
            $lines[] = 'Note de l’équipe : '.$sejour->note;
        }

        return self::withoutContacts(implode("\n", $lines), $this->knownNumbers($sejour));
    }

    /**
     * Ni numéro de téléphone ni adresse e-mail ne partent à l'IA (03.11.2026).
     * Cinquième vérification : aucun mot ne décide plus. Les formes fortes
     * sont toujours masquées : international en « + » ou « 00 » (9 à 15
     * chiffres), mobile suisse 075 à 079, mobile français 06 ou 07 en
     * paires (et fixe français 01 à 05 ou 09, en paires séparées), 0800 et
     * apparentés, fixe suisse en 3-3-2-2 ou en un bloc de
     * dix. Les numéros connus du séjour (propriétaire, contact d'urgence)
     * sont masqués sous toutes leurs formes, même collés à un mot. Le reste
     * (doses, heures, dates, valeurs, lots) passe tel quel ; un lot qui a la
     * forme d'un mobile est masqué, c'est accepté.
     *
     * @param  list<string|null>  $known
     */
    public static function withoutContacts(string $text, array $known = []): string
    {
        $text = (string) preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u', '[e-mail]', $text);

        // Espaces insécables et fines comptent comme des espaces (sixième vérification)
        $space = ' \x{00A0}\x{202F}\x{2009}';
        $any = '['.$space.'.\/()-]*';
        $sep = '['.$space.'.\/-]?';
        $strong = [
            '(?:\+|(?<!\d)00)[1-9](?:'.$any.'\d){8,14}',
            '(?<!\d)07[5-9](?:'.$any.'\d){7}(?!\d)',
            // En paires, toujours le même séparateur : « 06.11.2026 08:00 » n'en est pas un
            '(?<!\d)0[67]('.$sep.')\d{2}(?:\1\d{2}){3}(?!\d)',
            '(?<!\d)0[1-59](['.$space.'.\/-])\d{2}(?:\1\d{2}){3}(?!\d)',
            '(?<!\d)(?:0800|0840|0842|0844|0848|0900|0901|0906)(?:'.$any.'\d){6}(?!\d)',
            '(?<!\d)(?:\(0[1-9]\d\)|0[1-9]\d)'.$sep.'\d{3}'.$sep.'\d{2}'.$sep.'\d{2}(?!\d)',
            '(?<!\d)0[1-9]\d{8}(?!\d)',
        ];
        foreach (self::knownCores($known) as $core) {
            $strong[] = '(?:(?:\+|00)\d{1,3}'.$any.')?(?:\(?0\)?'.$any.')?'.implode($any, str_split($core));
        }

        // Un motif à la fois : chacun garde ses propres références
        foreach ($strong as $pattern) {
            $text = (string) preg_replace('/'.$pattern.'/u', '[téléphone]', $text);
        }

        return $text;
    }

    /**
     * Les chiffres qui reconnaissent un numéro connu, quelle que soit sa
     * forme : ses neuf derniers chiffres (sans indicatif ni zéro de tête).
     *
     * @param  list<string|null>  $known
     * @return list<string>
     */
    private static function knownCores(array $known): array
    {
        $cores = [];
        foreach ($known as $value) {
            preg_match_all('/[+\d][\d \x{00A0}\x{202F}\x{2009}.\/()-]*\d/u', (string) $value, $runs);
            foreach ($runs[0] as $run) {
                $digits = (string) preg_replace('/\D/', '', $run);
                if (strlen($digits) >= 7) {
                    $cores[] = substr(ltrim($digits, '0'), -9);
                }
            }
        }

        return array_values(array_unique($cores));
    }

    /**
     * Les numéros connus du séjour : le propriétaire figé à l'admission et
     * celui du dossier aujourd'hui, avec leurs contacts d'urgence.
     *
     * @return list<string|null>
     */
    private function knownNumbers(Sejour $sejour): array
    {
        $numbers = [];
        foreach (array_filter([$this->owner($sejour), $sejour->patient?->client]) as $client) {
            array_push($numbers, $client->tel, $client->emergency_phone, $client->emergency_contact);
        }

        return $numbers;
    }
}
