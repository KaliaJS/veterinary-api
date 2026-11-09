<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Models\AccountingLock;
use App\Models\ClotureCaisse;
use App\Models\Facture;
use App\Models\Societe;
use App\Services\Facturation\AccountingEntries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * L'export comptable d'un mois (point e, 09.11.2026) : les écritures de la
 * société de la clinique du jeton (toutes ses cliniques), et le verrou du
 * mois remis à la fiduciaire. Verrouiller et rouvrir : le droit de
 * clôturer la caisse. Le mois en cours ne se verrouille pas.
 */
class EcrituresComptablesController extends Controller
{
    public function index(Request $request, AccountingEntries $entries): JsonResponse
    {
        $this->authorize('viewAny', Facture::class);
        $mois = $this->mois((string) $request->query('mois'));
        $societe = $this->societe();
        $verrou = $this->actif($societe, $mois);

        return response()->json(['data' => [
            'mois' => $mois,
            'societe' => ['id' => $societe->id, 'nom' => $societe->nom, 'assujettie_tva' => $societe->assujettie_tva !== false],
            'verrou' => $this->verrou($societe, $mois),
            'peut_verrouiller' => $mois < $this->moisCourant(),
            // Remis à la fiduciaire : les écritures telles que remises (un plan changé ensuite ne les change pas)
            'lignes' => $verrou->entries ?? $entries->month($societe, $mois),
        ]]);
    }

    public function verrouiller(Request $request, string $mois, AccountingEntries $entries): JsonResponse
    {
        $this->authorize('create', ClotureCaisse::class);
        $mois = $this->mois($mois);
        if ($mois >= $this->moisCourant()) {
            throw ValidationException::withMessages(['mois' => 'Seul un mois terminé se remet à la fiduciaire.']);
        }
        $societe = $this->societe();
        if ($this->actif($societe, $mois) === null) {
            // Les écritures du jour du verrou, gardées telles quelles ; un verrou rouvert repart
            AccountingLock::query()->updateOrCreate(
                ['societe_id' => $societe->id, 'month' => $mois],
                ['locked_at' => now(), 'locked_by_id' => $request->user()->id, 'entries' => $entries->month($societe, $mois), 'reopened_at' => null, 'reopened_by_id' => null],
            );
        }

        return response()->json(['data' => ['mois' => $mois, 'verrou' => $this->verrou($societe, $mois)]]);
    }

    public function rouvrir(Request $request, string $mois): JsonResponse
    {
        $this->authorize('create', ClotureCaisse::class);
        $mois = $this->mois($mois);
        $societe = $this->societe();
        // Rouvert, pas effacé : qui et quand restent
        $this->actif($societe, $mois)?->update(['reopened_at' => now(), 'reopened_by_id' => $request->user()->id]);

        return response()->json(['data' => ['mois' => $mois, 'verrou' => null]]);
    }

    /** @return array{locked_at: string|null, par: string|null}|null */
    private function verrou(Societe $societe, string $mois): ?array
    {
        $verrou = $this->actif($societe, $mois)?->load('lockedBy');

        return $verrou === null ? null : ['locked_at' => $verrou->locked_at->toIso8601String(), 'par' => $verrou->lockedBy?->name];
    }

    private function actif(Societe $societe, string $mois): ?AccountingLock
    {
        return AccountingLock::query()->actif()->where('societe_id', $societe->id)->where('month', $mois)->first();
    }

    private function mois(string $mois): string
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mois) !== 1) {
            throw ValidationException::withMessages(['mois' => 'Le mois s’écrit AAAA-MM.']);
        }

        return $mois;
    }

    private function moisCourant(): string
    {
        return aujourdhui()->format('Y-m');
    }

    private function societe(): Societe
    {
        $societe = app(ContexteClinique::class)->clinique()?->societe;
        abort_if($societe === null, 404);

        return $societe;
    }
}
