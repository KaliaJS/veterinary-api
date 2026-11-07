<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\StatutOrdonnance;
use App\Enums\TypeMouvementStupefiant;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Stupefiants\EnregistrerMouvementStupefiantRequest;
use App\Http\Resources\V1\MouvementStupefiantResource;
use App\Models\Lot;
use App\Models\MouvementStupefiant;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Models\Produit;
use App\Services\RegistreStupefiants;
use App\Services\Stocks\FefoLots;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Registre des stupéfiants de la clinique du jeton (art. 57, 63 et 64
 * OCStup, docs/data-model.md §16) : journal immuable, création seule.
 * Une fiche par substance (`produit_id`), chaque ligne avec son type et le
 * stock après elle (mise aux normes, 01.11.2026). Le registre se lit PAR
 * CLINIQUE : c'est elle qui le tient et le présente aux contrôles.
 */
class MouvementStupefiantController extends Controller
{
    public function __construct(private RegistreStupefiants $registre) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MouvementStupefiant::class);

        $mouvements = MouvementStupefiant::query()
            ->with(['produit', 'corrige'])
            ->when($request->integer('produit_id'), fn ($requete, int $produit) => $requete->where('produit_id', $produit))
            ->when($request->integer('patient_id'), fn ($requete, int $patient) => $requete->where('patient_id', $patient))
            ->latest('created_at')
            ->latest('id')
            ->paginate($this->parPage($request));

        return MouvementStupefiantResource::collection($mouvements);
    }

    /**
     * Les ordonnances à proposer pour une remise (01.11.2026) : celles en
     * cours de l'animal qui prescrivent la substance. Le numéro, la date et
     * le vétérinaire seulement : le registre n'a pas à lire la prescription.
     */
    public function ordonnances(Request $request): JsonResponse
    {
        // Ce qu'un animal prend : le droit d'inscrire au registre (Stupéfiants), une substance du registre
        $this->authorize('create', MouvementStupefiant::class);
        $donnees = $request->validate([
            'patient_id' => ['required', 'integer'],
            'produit_id' => ['required', 'integer', Rule::exists('produits', 'id')->where('organisation_id', app(ContexteClinique::class)->organisationId())->where('est_stupefiant', true)],
        ], ['produit_id.exists' => 'Ce produit n’est pas une substance du registre.']);

        // Le vétérinaire peut être d'une clinique sœur : sa fiche se lit hors du scope de la clinique du jeton
        $ordonnances = Ordonnance::query()->with(['employe' => fn ($employe) => $employe->withoutGlobalScopes()])
            ->where('patient_id', $donnees['patient_id'])
            ->where('statut', StatutOrdonnance::Active)
            ->whereHas('lignes', fn ($ligne) => $ligne->where('produit_id', $donnees['produit_id']))
            ->latest('signee_le')->latest('id')->limit(20)->get();

        return response()->json(['data' => $ordonnances->map(fn (Ordonnance $o): array => [
            'id' => $o->id,
            'numero' => $o->numero,
            'signee_le' => $o->signee_le->toDateString(),
            'veterinaire' => $o->employe?->nom,
        ])->all()]);
    }

    public function store(EnregistrerMouvementStupefiantRequest $request): MouvementStupefiantResource
    {
        $this->authorize('create', MouvementStupefiant::class);

        $donnees = $request->validated();
        $type = TypeMouvementStupefiant::from($donnees['type']);
        $cliniqueId = (int) app(ContexteClinique::class)->cliniqueId();

        // Une sortie plus grande que son lot s'écrit en une ligne par lot (06.11.2026)
        $lignes = DB::transaction(function () use ($donnees, $type, $cliniqueId, $request): Collection {
            $produit = Produit::query()->findOrFail($donnees['produit_id']);
            $details = [
                'motif' => filled($donnees['motif'] ?? null) ? trim($donnees['motif']) : $type->libelle(),
                'patient' => $type->versUnAnimal() ? Patient::query()->with('client')->findOrFail($donnees['patient_id']) : null,
                // Une remise : l'ordonnance de l'app (tableau b) ou le numéro du formulaire officiel (tableau a), contrôlés par la requête
                'ordonnance' => filled($donnees['ordonnance_id'] ?? null) ? Ordonnance::query()->findOrFail($donnees['ordonnance_id']) : null,
                // « 07012345 » et « 7012345 » : le même formulaire
                'ordonnance_officielle' => filled($donnees['ordonnance_officielle'] ?? null) ? ltrim((string) $donnees['ordonnance_officielle'], '0') : null,
                'ordonnance_officielle_le' => $donnees['ordonnance_officielle_le'] ?? null,
                'fournisseur' => $donnees['fournisseur'] ?? null,
                'bulletin' => $donnees['bulletin'] ?? null,
                'bulletin_le' => $donnees['bulletin_le'] ?? null,
                // Le lot choisi pour une sortie (contrôlé par la requête) ; sans lui, le registre inscrit le premier en FEFO
                'lot' => filled($donnees['lot_id'] ?? null) ? Lot::query()->findOrFail($donnees['lot_id']) : null,
            ];
            // Une réception manuelle entre dans son lot (décision de Jim, 07.11.2026)
            if ($type === TypeMouvementStupefiant::Reception) {
                $details['lot'] = $this->lotRecu($produit, $cliniqueId, (string) $donnees['numero_lot'], (string) $donnees['lot_peremption']);
            }

            $quantite = match ($type) {
                TypeMouvementStupefiant::Correction => $this->correction($donnees, $produit, $cliniqueId, $details),
                TypeMouvementStupefiant::Inventaire => $this->inventaire($donnees, $produit, $cliniqueId, $details),
                TypeMouvementStupefiant::Visa => 0.0,
                default => $type->sens() * (float) $donnees['quantite'],
            };

            return $this->registre->ecrireParLot($produit, $type, $quantite, $details, $cliniqueId, $request->user());
        });
        $lignes = (new EloquentCollection($lignes->all()))->load(['produit', 'corrige']);

        // La dernière ligne (le stock après l'inscription), avec toutes les lignes écrites
        $resource = new MouvementStupefiantResource($lignes->last());
        $resource->lignesEcrites = $lignes;

        return $resource;
    }

    /**
     * Le lot d'une réception manuelle : retrouvé au même numéro et au même
     * mois de péremption dans la clinique (un lot reçu par commande au
     * 31.03.2028 est celui de « 2028-03 »), sinon créé vide ; la réception y
     * fait entrer sa quantité. Sous le verrou de la substance.
     */
    private function lotRecu(Produit $produit, int $cliniqueId, string $numero, string $peremption): Lot
    {
        $this->registre->soldeVerrouille($produit, $cliniqueId);
        $mois = CarbonImmutable::createFromFormat('!Y-m', $peremption);

        return app(FefoLots::class)->lotByNumber($produit->id, $cliniqueId, $numero, Carbon::parse($mois->toDateString()));
    }

    /**
     * Une correction pointe une ligne de la même substance, de la clinique,
     * qui n'est ni un visa ni elle-même une correction.
     *
     * @param  array<string, mixed>  $donnees
     * @param  array<string, mixed>  $details
     */
    private function correction(array $donnees, Produit $produit, int $cliniqueId, array &$details): float
    {
        // La substance d'abord sous verrou, puis la ligne et ses corrections en lecture verrouillante : deux
        // corrections envoyées au même instant passent l'une après l'autre, la seconde voit la première
        $this->registre->soldeVerrouille($produit, $cliniqueId);
        $corrigee = MouvementStupefiant::query()->whereKey($donnees['corrige_id'])->where('produit_id', $produit->id)->lockForUpdate()->first();
        if ($corrigee === null || in_array($corrigee->type, [TypeMouvementStupefiant::Visa, TypeMouvementStupefiant::Correction], true)) {
            throw ValidationException::withMessages(['corrige_id' => 'Choisissez une ligne de cette substance à corriger.']);
        }
        $details['corrige_id'] = $corrigee->id;
        $quantite = (float) $donnees['quantite'];

        // Une correction redresse la ligne, sans la dépasser ni en inverser le sens (vérification du 01.11.2026)
        $origine = (float) $corrigee->quantite;
        $net = $origine + (float) MouvementStupefiant::query()->where('corrige_id', $corrigee->id)->where('type', TypeMouvementStupefiant::Correction)
            ->lockForUpdate()->get(['quantite'])->sum(fn (MouvementStupefiant $c): float => (float) $c->quantite);
        $apres = $net + $quantite;
        if ($apres * $origine < -0.0000005 || abs($apres) > abs($origine) + 0.0005) {
            // Une inscription écrite sur plusieurs lots se corrige ligne par ligne (06.11.2026)
            $inscription = $corrigee->premiere_ligne_id ?? $corrigee->id;
            $nombre = MouvementStupefiant::query()->where(fn ($ligne) => $ligne->whereKey($inscription)->orWhere('premiere_ligne_id', $inscription))->count();
            throw ValidationException::withMessages(['quantite' => 'Une correction ne peut pas dépasser la ligne corrigée ('.RegistreStupefiants::quantite($origine).').'
                .($nombre > 1 ? ' Elle s’est inscrite en '.$nombre.' lignes, une par lot : corrigez chaque ligne pour son lot.' : '')]);
        }
        // Une remise sur formulaire officiel annulée, dont le formulaire a resservi : elle ne se rétablit plus (art. 51, al. 3)
        if ($corrigee->type === TypeMouvementStupefiant::Remise && $corrigee->ordonnance_id === null && filled($corrigee->ordonnance_numero)
            && abs($net) < 0.0005 && abs($apres) >= 0.0005) {
            // Les lignes de la même inscription (une par lot, 06.11.2026) sont la même remise
            $inscription = $corrigee->premiere_ligne_id ?? $corrigee->id;
            $autres = MouvementStupefiant::withoutGlobalScopes()->where('produit_id', $produit->id)->where('type', TypeMouvementStupefiant::Remise)
                ->whereNull('ordonnance_id')->where('ordonnance_numero', $corrigee->ordonnance_numero)->whereKeyNot($inscription)
                ->where(fn ($ligne) => $ligne->whereNull('premiere_ligne_id')->orWhere('premiere_ligne_id', '!=', $inscription))
                ->lockForUpdate()->get(['id', 'quantite']);
            $actives = $autres->filter(fn (MouvementStupefiant $r): bool => abs((float) $r->quantite + (float) MouvementStupefiant::withoutGlobalScopes()->where('corrige_id', $r->id)->sum('quantite')) > 0.0005);
            if ($actives->isNotEmpty()) {
                throw ValidationException::withMessages(['corrige_id' => 'Le formulaire officiel de cette remise a resservi à une autre remise : elle ne se rétablit plus.']);
            }
        }

        return $quantite;
    }

    /**
     * L'inventaire : ce qui est compté contre ce que dit le registre ; un
     * écart doit être expliqué (OSAV D 06 : écarts justifiés et corrigés).
     *
     * @param  array<string, mixed>  $donnees
     * @param  array<string, mixed>  $details
     */
    private function inventaire(array $donnees, Produit $produit, int $cliniqueId, array &$details): float
    {
        $compte = round((float) $donnees['quantite_comptee'], 3);
        $ecart = round($compte - $this->registre->soldeVerrouille($produit, $cliniqueId), 3);
        if ($ecart !== 0.0 && blank($donnees['motif'] ?? null)) {
            throw ValidationException::withMessages(['motif' => 'Le compte diffère du registre de '.RegistreStupefiants::quantite($ecart).' : expliquez l’écart.']);
        }
        $details['quantite_comptee'] = $compte;
        if ($ecart === 0.0 && blank($donnees['motif'] ?? null)) {
            $details['motif'] = 'Stock compté, conforme au registre';
        }

        return $ecart;
    }
}
