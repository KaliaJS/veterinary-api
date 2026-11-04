<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Events\DentalChartChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\DossierMedical\ModifierBilanDentaireRequest;
use App\Http\Requests\V1\DossierMedical\ModifierDentRequest;
use App\Http\Requests\V1\DossierMedical\RemplacerReleveDentaireRequest;
use App\Models\Clinique;
use App\Models\DentalOverview;
use App\Models\Patient;
use App\Models\ReleveDentaire;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Le schéma dentaire d'un patient (04.11.2026) : `teeth`, numéro Triadan →
 * { states, note } (une dent saine et sans note n'y est pas), et `overview`,
 * le bilan de la bouche. Le PUT remplace le relevé entier (gardé pour la
 * compatibilité) ; l'app envoie dent par dent (`PATCH …/dents/{dent}`) et
 * le bilan à part (`PATCH …/dents/bilan`) : chacun n'écrit que ce qu'il
 * reçoit, et deux postes gardent chacun leurs dents. Chaque changement
 * s'annonce aux cliniques de l'organisation (Reverb), pour qu'une fenêtre
 * ouverte relise.
 */
class ReleveDentaireController extends Controller
{
    public function afficher(Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        return $this->reponse($patient);
    }

    public function remplacer(RemplacerReleveDentaireRequest $request, Patient $patient): JsonResponse
    {
        /** @var array<string, array{states: array<int, string>, note?: string|null}> $teeth */
        $teeth = $request->validated('teeth');
        /** @var array{gingivitis?: string|null, calculus?: string|null, occlusion?: string|null, local_block?: string|null} $overview */
        $overview = $request->validated('overview');

        // Un interblocage (MySQL 1213) se rejoue seul, trois fois au plus
        DB::transaction(function () use ($patient, $teeth, $overview): void {
            // Une dent inchangée garde la clinique qui l'a relevée (provenance, 02.11.2026)
            $avant = $patient->relevesDentaires()->get()->keyBy('dent');
            $patient->relevesDentaires()->delete();

            foreach ($teeth as $dent => $releve) {
                $etats = array_values($releve['states']);
                $note = trim((string) ($releve['note'] ?? '')) ?: null;
                if ($etats === [] && $note === null) {
                    continue;
                }
                $ancien = $avant->get((int) $dent);
                $inchange = $ancien !== null && $ancien->etats === $etats && $ancien->note === $note;
                $patient->relevesDentaires()->create([
                    'dent' => (int) $dent,
                    'etats' => $etats,
                    'note' => $note,
                    ...($inchange ? ['source_organisation_id' => $ancien->source_organisation_id] : []),
                ]);
            }

            $bilan = [
                'gingivitis' => $overview['gingivitis'] ?? null,
                'calculus' => $overview['calculus'] ?? null,
                'occlusion' => $overview['occlusion'] ?? null,
                'local_block' => trim((string) ($overview['local_block'] ?? '')) ?: null,
            ];
            $this->ecrireBilan($patient, $bilan);
        }, 3);

        $this->annoncer($patient);

        return $this->reponse($patient);
    }

    /**
     * Une dent seule : ses états et sa note ; sans rien, elle redevient saine.
     * Rien de changé : rien d'écrit, rien d'annoncé.
     */
    public function modifierDent(ModifierDentRequest $request, Patient $patient, int $dent): JsonResponse
    {
        /** @var array<int, string> $etats */
        $etats = array_values($request->validated('states'));
        $note = trim((string) $request->validated('note')) ?: null;

        $change = $this->unSeulGagnant(fn (): bool => DB::transaction(function () use ($patient, $dent, $etats, $note): bool {
            $releve = $patient->relevesDentaires()->where('dent', $dent)->lockForUpdate()->first();
            if ($etats === [] && $note === null) {
                return (bool) $releve?->delete();
            }
            if ($releve === null) {
                $patient->relevesDentaires()->create(['dent' => $dent, 'etats' => $etats, 'note' => $note]);

                return true;
            }
            if ($releve->etats === $etats && $releve->note === $note) {
                return false;
            }
            // Changée : la provenance devient l'organisation qui l'a relevée (02.11.2026)
            $releve->update(['etats' => $etats, 'note' => $note, 'source_organisation_id' => $this->organisation()]);

            return true;
        }, 3));

        if ($change) {
            $this->annoncer($patient);
        }

        return $this->reponse($patient);
    }

    /** Le bilan de la bouche : seuls les champs reçus changent */
    public function modifierBilan(ModifierBilanDentaireRequest $request, Patient $patient): JsonResponse
    {
        $champs = $request->validated();
        if (array_key_exists('local_block', $champs)) {
            $champs['local_block'] = trim((string) $champs['local_block']) ?: null;
        }

        $change = $this->unSeulGagnant(fn (): bool => DB::transaction(fn (): bool => $this->ecrireBilan($patient, $champs), 3));

        if ($change) {
            $this->annoncer($patient);
        }

        return $this->reponse($patient);
    }

    /**
     * Le bilan, dans la transaction en cours : écrit seulement s'il change,
     * avec la provenance de qui le change.
     *
     * @param  array<string, string|null>  $champs
     */
    private function ecrireBilan(Patient $patient, array $champs): bool
    {
        $bilan = $patient->dentalOverview()->lockForUpdate()->first();
        if ($bilan === null) {
            if (array_filter($champs) === []) {
                return false;
            }
            $patient->dentalOverview()->create($champs);

            return true;
        }
        $bilan->fill($champs);
        if (! $bilan->isDirty()) {
            return false;
        }
        $bilan->source_organisation_id = $this->organisation();
        $bilan->save();

        return true;
    }

    /**
     * Deux envois en même temps sur une dent (ou un bilan) qui n'avait pas
     * de ligne : le second bute sur la ligne née du premier (1062) ; il
     * recommence une fois et la met à jour. Les interblocages (1213) se
     * rejouent dans la transaction elle-même (trois tentatives).
     *
     * @param  callable(): bool  $ecrire
     */
    private function unSeulGagnant(callable $ecrire): bool
    {
        try {
            return $ecrire();
        } catch (UniqueConstraintViolationException) {
            return $ecrire();
        }
    }

    private function organisation(): ?int
    {
        return app(ContexteClinique::class)->organisationId();
    }

    /**
     * Toutes les cliniques de l'organisation du patient : le dossier est
     * partagé. L'annonce part pendant la requête (ShouldBroadcastNow, comme
     * les autres événements du projet) ; Reverb injoignable ne fait jamais
     * échouer un geste déjà enregistré (l'erreur est signalée au journal).
     */
    private function annoncer(Patient $patient): void
    {
        $cliniques = Clinique::query()
            ->whereHas('societe', fn ($societe) => $societe->where('organisation_id', $patient->organisation_id))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        rescue(fn () => DentalChartChanged::dispatch($patient->id, $cliniques));
    }

    private function reponse(Patient $patient): JsonResponse
    {
        $teeth = $patient->relevesDentaires()
            ->orderBy('dent')
            ->get()
            ->mapWithKeys(fn (ReleveDentaire $releve) => [(string) $releve->dent => $releve->pourSchema()]);

        return response()->json(['data' => [
            'teeth' => $teeth,
            'overview' => DentalOverview::pourSchema($patient->dentalOverview()->first()),
        ]]);
    }
}
