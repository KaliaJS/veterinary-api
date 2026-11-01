<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\CalculateurPermissions;
use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Enums\TypeMouvementStupefiant;
use App\Http\Controllers\Controller;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\MouvementStupefiant;
use App\Models\Produit;
use App\Services\RegistreStupefiants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Le visa du registre des stupéfiants, réservé au vétérinaire responsable
 * (décision de Jim, 01.11.2026). Un administrateur le désigne parmi les
 * vétérinaires de la clinique ; lui seul vise, toutes les substances tenues
 * d'un coup, dans une seule transaction (jamais un registre à moitié visé).
 * Le responsable doit pouvoir viser au moment même : vétérinaire, accès
 * actif, contrat en cours, droit d'inscrire au registre ; sinon personne
 * n'est responsable (vérification du 01.11.2026). Retiré de la clinique ou
 * plus vétérinaire, sa désignation tombe (Employe::booted, affectations).
 */
class VisaStupefiantController extends Controller
{
    public function __construct(private RegistreStupefiants $registre, private CalculateurPermissions $permissions) {}

    /** Le responsable désigné, et les vétérinaires de la clinique qui peuvent l'être */
    public function show(): JsonResponse
    {
        $this->authorize('viewAny', MouvementStupefiant::class);

        return response()->json(['data' => $this->etat()]);
    }

    /** Désigner (ou retirer) le responsable : droit Organisation, un vétérinaire de la clinique */
    public function designer(Request $request): JsonResponse
    {
        $donnees = $request->validate(['employe_id' => ['present', 'nullable', 'integer']]);
        $clinique = app(ContexteClinique::class)->clinique();
        $employe = $donnees['employe_id'] === null ? null : Employe::query()->find($donnees['employe_id']);
        if ($donnees['employe_id'] !== null && ($employe === null || ! $this->peutViser($employe))) {
            throw ValidationException::withMessages(['employe_id' => 'Le responsable des stupéfiants est un vétérinaire de la clinique, avec un accès actif et le droit Stupéfiants.']);
        }
        $clinique->update(['responsable_stupefiants_id' => $employe?->id]);

        return response()->json(['data' => $this->etat()]);
    }

    /** Viser : toutes les substances tenues (un stock ou une ligne), par le responsable seulement */
    public function viser(Request $request): JsonResponse
    {
        $this->authorize('create', MouvementStupefiant::class);
        $mention = trim((string) ($request->validate(['mention' => ['nullable', 'string', 'max:500']])['mention'] ?? ''));
        $contexte = app(ContexteClinique::class);
        $clinique = $contexte->clinique();
        $responsable = $this->responsable($clinique);
        if ($responsable === null) {
            throw ValidationException::withMessages(['visa' => 'Aucun vétérinaire responsable n’est désigné pour cette clinique : un administrateur le désigne dans l’onglet Stupéfiants.']);
        }
        if ($contexte->employe()?->id !== $responsable->id) {
            throw ValidationException::withMessages(['visa' => 'Seul le vétérinaire responsable, '.$responsable->nom.', vise le registre.']);
        }

        $visees = DB::transaction(function () use ($mention, $clinique, $request): int {
            $substances = Produit::query()->where('est_stupefiant', true)->whereHas('mouvementsStupefiants')->orderBy('nom')->get();
            foreach ($substances as $produit) {
                $this->registre->ecrire($produit, TypeMouvementStupefiant::Visa, 0.0, ['motif' => $mention !== '' ? $mention : TypeMouvementStupefiant::Visa->libelle()], (int) $clinique->id, $request->user());
            }

            return $substances->count();
        });

        return response()->json(['data' => ['visees' => $visees]]);
    }

    /** @return array{responsable: ?array{id: int, nom: string}, candidats: list<array{id: int, nom: string}>} */
    private function etat(): array
    {
        $responsable = $this->responsable(app(ContexteClinique::class)->clinique()->fresh());

        return [
            'responsable' => $responsable ? ['id' => $responsable->id, 'nom' => $responsable->nom] : null,
            'candidats' => Employe::query()->with(['role', 'derogations'])->orderBy('nom')->get()->filter(fn (Employe $e): bool => $this->peutViser($e))
                ->map(fn (Employe $e): array => ['id' => $e->id, 'nom' => $e->nom])->values()->all(),
        ];
    }

    /** Le responsable désigné, s'il peut viser aujourd'hui ; null sinon */
    private function responsable(Clinique $clinique): ?Employe
    {
        $employe = $clinique->responsable_stupefiants_id ? Employe::query()->find($clinique->responsable_stupefiants_id) : null;

        return $employe !== null && $this->peutViser($employe) ? $employe : null;
    }

    /** Vétérinaire, accès actif et contrat en cours, droits Stocks et Stupéfiants (ceux du visa) */
    private function peutViser(Employe $employe): bool
    {
        $droits = $this->permissions->pour($employe);

        return $employe->estVeterinaire() && $employe->estPresentable()
            && in_array(DomainePermission::Stocks->value, $droits, true)
            && in_array(DomainePermission::Stupefiants->value, $droits, true);
    }
}
