<?php

namespace App\Jobs;

use App\Enums\StatutEnregistrement;
use App\Models\EnregistrementConsultation;
use App\Models\PartieEnregistrement;
use App\Services\BudgetAnthropic;
use App\Services\Transcription\BrouillonSoap;
use App\Services\Transcription\Redacteur;
use App\Services\Transcription\RedactionImpossible;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Rédige le brouillon SOAP d'un enregistrement dont toutes les parties
 * sont transcrites. Le brouillon attend la relecture du vétérinaire : le
 * compte rendu n'est enregistré qu'à sa validation. Sans aucune parole
 * reconnue, le brouillon reste vide sans appeler le rédacteur.
 */
final class RedigerCompteRendu implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 400;

    public function __construct(public int $enregistrementId) {}

    /**
     * Jamais deux fois en même temps pour le même enregistrement : une
     * tâche reprise par un second worker pendant que le premier attend
     * le fournisseur ne relance pas un appel payant (elle repasse plus
     * tard et trouve le travail fait).
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('redaction-compte-rendu:'.$this->enregistrementId))->releaseAfter(60)->expireAfter($this->timeout + 100)];
    }

    /** Le vétérinaire a terminé et la dernière partie est transcrite : la rédaction part, une seule fois. */
    public static function siPret(EnregistrementConsultation $enregistrement): void
    {
        $enregistrement->refresh();
        if ($enregistrement->statut !== StatutEnregistrement::Transcription || ! $enregistrement->partiesTranscrites()) {
            return;
        }

        // Bornes (23.10.2026) : quelques rédactions par enregistrement, et le
        // budget de la plateforme chez Anthropic
        $refus = match (true) {
            $enregistrement->redactions >= (int) config('transcription.redactions_max') => 'redactions',
            app(BudgetAnthropic::class)->atteint() => 'budget',
            default => null,
        };
        if ($refus !== null) {
            EnregistrementConsultation::withoutGlobalScopes()
                ->whereKey($enregistrement->id)
                ->where('statut', StatutEnregistrement::Transcription->value)
                ->update(['statut' => StatutEnregistrement::Erreur->value, 'erreur' => $refus]);

            return;
        }

        $pris = EnregistrementConsultation::withoutGlobalScopes()
            ->whereKey($enregistrement->id)
            ->where('statut', StatutEnregistrement::Transcription->value)
            ->update(['statut' => StatutEnregistrement::Redaction->value, 'erreur' => null, 'redactions' => $enregistrement->redactions + 1]);

        if ($pris === 1) {
            self::dispatch($enregistrement->id);
        }
    }

    public function handle(Redacteur $redacteur): void
    {
        $enregistrement = EnregistrementConsultation::withoutGlobalScopes()->with(['parties', 'rendezVous.patient'])->find($this->enregistrementId);
        if (! $enregistrement || $enregistrement->statut !== StatutEnregistrement::Redaction) {
            return;
        }

        $segments = $enregistrement->parties
            ->flatMap(fn (PartieEnregistrement $partie) => collect($partie->segments ?? [])->map(fn (array $segment) => [
                'partie' => $partie->numero,
                'voix' => (int) $segment['voix'],
                'debut' => (float) $segment['debut'],
                'texte' => (string) $segment['texte'],
            ]))
            ->values()
            ->all();

        if ($segments === []) {
            $brouillon = new BrouillonSoap('', '', '', '', [
                ['rubrique' => 's', 'passage' => '', 'entendu' => '', 'raison' => 'Aucune parole n’a été reconnue dans l’enregistrement.'],
            ]);
        } else {
            try {
                if ($this->attempts() > 1) {
                    BudgetAnthropic::nouvelEssai('redaction');
                }
                $brouillon = $redacteur->rediger($segments, self::contexte($enregistrement));
            } catch (RedactionImpossible $erreur) {
                report($erreur);
                $this->echec();

                return;
            }
        }

        $enregistrement->forceFill([
            'brouillon' => $brouillon->toArray(),
            'statut' => StatutEnregistrement::Pret,
            'erreur' => null,
            'redige_le' => now(),
        ])->save();
    }

    public function failed(?Throwable $erreur): void
    {
        $this->echec();
    }

    private function echec(): void
    {
        EnregistrementConsultation::withoutGlobalScopes()
            ->whereKey($this->enregistrementId)
            ->where('statut', StatutEnregistrement::Redaction->value)
            ->update(['statut' => StatutEnregistrement::Erreur->value, 'erreur' => 'redaction']);
    }

    /**
     * Ce que le dossier dit de l'animal, pour que le rédacteur comprenne
     * la conversation (jamais recopié dans le compte rendu).
     *
     * @return array<string, string>
     */
    private static function contexte(EnregistrementConsultation $enregistrement): array
    {
        $rendezVous = $enregistrement->rendezVous;
        $patient = $rendezVous?->patient;

        return array_filter([
            'Animal' => $patient->nom ?? $rendezVous?->patient_name,
            'Espèce' => ($patient->espece ?? $rendezVous?->species)?->libelle(),
            'Race' => $patient?->race,
            'Sexe' => $patient?->sexe?->value,
            'Âge' => $patient?->ne_le ? self::age($patient->ne_le) : null,
            'Motif du rendez-vous' => $rendezVous?->reason,
        ], fn ($valeur) => is_string($valeur) && $valeur !== '');
    }

    private static function age(CarbonInterface $naissance): string
    {
        $mois = (int) $naissance->diffInMonths(now());

        return $mois < 24 ? $mois.' mois' : intdiv($mois, 12).' ans';
    }
}
