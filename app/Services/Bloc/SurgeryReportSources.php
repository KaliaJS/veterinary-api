<?php

namespace App\Services\Bloc;

use App\Enums\StatutEnregistrement;
use App\Enums\StatutIntervention;
use App\Models\EnregistrementConsultation;
use App\Models\Intervention;
use App\Models\InterventionChecklistPoint;
use App\Models\InterventionTransition;
use Carbon\CarbonInterface;

/**
 * Les sources du rapport de bloc, numérotées (09.11.2026) : E, ce que
 * l'écran du bloc sait (l'intervention, le déroulé, chaque ligne du
 * protocole anesthésique, la checklist) ; N, chaque ligne non vide de la
 * note opératoire ; D, chaque passage de la dernière dictée transcrite.
 * L'IA ne cite que ces numéros.
 */
final class SurgeryReportSources
{
    private const STEPS = [
        'prep' => 'Préparation',
        'cours' => 'En cours',
        'reveil' => 'Réveil',
        'fini' => 'Fini',
    ];

    /** @return list<array{id: string, kind: string, label: string, text: string}> */
    public function for(Intervention $intervention): array
    {
        $intervention->loadMissing(['patient', 'chirurgien', 'checklist', 'transitions', 'clinique']);
        $zone = $intervention->clinique?->fuseau_horaire ?: 'Europe/Zurich';
        $time = fn (?CarbonInterface $at) => $at?->copy()->setTimezone($zone)->format('H:i');

        $screen = [];
        $patient = $intervention->patient;
        $screen[] = ['label' => 'Intervention', 'text' => implode(' · ', array_filter([
            $intervention->procedure,
            $patient ? trim(implode(', ', array_filter([$patient->nom, $patient->espece->libelle(), $patient->race]))) : null,
            $intervention->chirurgien?->nom,
            $intervention->salle_nom,
        ]))];

        $steps = $intervention->transitions
            ->filter(fn (InterventionTransition $t) => isset(self::STEPS[$t->statut->value]))
            ->map(fn (InterventionTransition $t) => self::STEPS[$t->statut->value].' '.$time($t->created_at))
            ->values()
            ->all();
        if ($intervention->incision_a) {
            $steps[] = 'Incision '.$time($intervention->incision_a);
        }
        if ($steps !== []) {
            $screen[] = ['label' => 'Déroulé', 'text' => implode(' · ', $steps)];
        }

        foreach ($intervention->protocole_anesthesique ?? [] as $line) {
            if (trim((string) $line) !== '') {
                $screen[] = ['label' => 'Protocole anesthésique', 'text' => trim((string) $line)];
            }
        }

        if ($intervention->checklist->isNotEmpty()) {
            $screen[] = ['label' => 'Checklist', 'text' => $intervention->checklist
                ->map(fn (InterventionChecklistPoint $p) => $p->libelle.' : '.($p->est_coche ? 'fait' : 'non fait'))
                ->implode(' · ')];
        }

        $sources = [];
        foreach ($screen as $i => $fact) {
            $sources[] = ['id' => 'E'.($i + 1), 'kind' => 'screen', ...$fact];
        }

        $n = 0;
        foreach (preg_split('/\R/', (string) $intervention->note_operatoire) ?: [] as $line) {
            if (trim($line) !== '') {
                $sources[] = ['id' => 'N'.(++$n), 'kind' => 'note', 'label' => 'Note', 'text' => trim($line)];
            }
        }

        $d = 0;
        foreach ($this->dictation($intervention) as $segment) {
            $sources[] = ['id' => 'D'.(++$d), 'kind' => 'dictation', 'label' => $segment['label'], 'text' => $segment['text']];
        }

        return $sources;
    }

    /**
     * Les passages des dictées transcrites (prêtes, ou validées avec le
     * rapport), dans l'ordre : compléter une dictée en ajoute une neuve
     * (5e vérification)
     *
     * @return list<array{label: string, text: string}>
     */
    private function dictation(Intervention $intervention): array
    {
        $recordings = EnregistrementConsultation::withoutGlobalScopes()
            ->where('intervention_id', $intervention->id)
            ->whereIn('statut', [StatutEnregistrement::Pret->value, StatutEnregistrement::Valide->value])
            ->orderBy('id')
            ->with('parties')
            ->get();
        $isManyRecordings = $recordings->count() > 1;

        $out = [];
        foreach ($recordings->values() as $index => $recording) {
            $isManyParts = $recording->parties->count() > 1;
            foreach ($recording->parties as $part) {
                foreach ($part->segments ?? [] as $segment) {
                    $text = trim((string) ($segment['texte'] ?? ''));
                    if ($text === '') {
                        continue;
                    }
                    $seconds = (int) ($segment['debut'] ?? 0);
                    $prefix = ($isManyRecordings ? 'Dictée '.($index + 1).', ' : '').($isManyParts ? 'partie '.$part->numero.', ' : '');
                    $out[] = ['label' => ucfirst($prefix.sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60)), 'text' => $text];
                }
            }
        }

        return $out;
    }

    /** Une intervention se rapporte une fois finie, ou au réveil */
    public static function isReportable(Intervention $intervention): bool
    {
        return in_array($intervention->statut, [StatutIntervention::Reveil, StatutIntervention::Fini], true);
    }
}
