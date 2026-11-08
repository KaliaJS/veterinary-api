<?php

namespace App\Http\Controllers\Api\V1\Isabv;

use App\Auth\ContexteClinique;
use App\Enums\DeclarationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Concerns\RulesForIsabv;
use App\Http\Resources\V1\AntibioticDeclarationResource;
use App\Jobs\SendAntibioticDeclaration;
use App\Models\AntibioticDeclaration;
use App\Models\Clinique;
use App\Models\IsabvConnection;
use App\Services\Isabv\ConsultationCounts;
use App\Services\Isabv\DeclarationRules;
use App\Services\Isabv\Declarations;
use App\Services\Isabv\DeclarationXml;
use App\Services\Isabv\IsabvClient;
use App\Services\Isabv\IsabvTokenRefused;
use App\Services\Isabv\IsabvUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Les déclarations SI ABV de la clinique (08.11.2026) : à compléter, en
 * attente, envoyées, refusées ; le vétérinaire les complète ou les corrige.
 * Le nombre de consultations par espèce et par année part d'ici, et les
 * déclarations AV et FZL écartées à tort comme doublons se réactivent.
 * Lecture : droit « Patients » ; geste : droit « Prescrire ».
 */
class AntibioticDeclarationController extends Controller
{
    use RulesForIsabv;

    /** Les groupes de la liste */
    public const FILTERS = ['to_do' => ['to_complete', 'rejected'], 'waiting' => ['ready', 'replaced'], 'sent' => ['sent', 'web'], 'deactivated' => ['deactivated']];

    public function __construct(private ContexteClinique $contexte, private Declarations $declarations) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filter = $request->validate(['filter' => ['sometimes', Rule::in(array_keys(self::FILTERS))]])['filter'] ?? null;
        $page = AntibioticDeclaration::query()
            ->with(['patient', 'employe' => fn ($q) => $q->withoutGlobalScope('clinique'), 'lines.produit', 'lines.preparation', 'source'])
            ->when($filter, fn ($q) => $q->whereIn('status', self::FILTERS[$filter]))
            ->orderByRaw("case when status in ('to_complete', 'rejected') then 0 else 1 end")
            ->orderByDesc('visit_on')->orderByDesc('id')
            ->paginate(50);
        $facets = AntibioticDeclaration::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);

        return AntibioticDeclarationResource::collection($page)->additional(['meta' => [
            'facets' => collect(self::FILTERS)->map(fn (array $statuses) => (int) $facets->only($statuses)->sum()),
            'connection' => $this->connectionState(),
        ]]);
    }

    public function show(AntibioticDeclaration $declaration): AntibioticDeclarationResource
    {
        return new AntibioticDeclarationResource($declaration->load(['patient', 'employe' => fn ($q) => $q->withoutGlobalScope('clinique'), 'lines.produit', 'lines.preparation', 'source']));
    }

    public function update(Request $request, AntibioticDeclaration $declaration): AntibioticDeclarationResource
    {
        Gate::authorize('prescrire');
        $data = $request->validate([
            ...$this->isabvRules('lines'),
            'lines' => ['sometimes', 'array'],
            'lines.*.id' => ['required_with:lines', 'integer'],
        ]);
        $lineIds = $declaration->lines()->pluck('id')->all();
        $lineInputs = null;
        if (isset($data['lines'])) {
            $lineInputs = [];
            foreach ($data['lines'] as $line) {
                if (! in_array((int) $line['id'], $lineIds, true)) {
                    throw ValidationException::withMessages(['lines' => 'Cette ligne n’est pas de cette déclaration.']);
                }
                $lineInputs[(int) $line['id']] = $line['isabv'] ?? [];
            }
        }
        $updated = $this->declarations->update($declaration, $data['isabv'] ?? [], $lineInputs);

        return $this->show($updated);
    }

    /**
     * Un traitement oral d'un groupe d'animaux de rente se saisit dans
     * l'application web de l'OSAV (directives, ch. 2.8) : le vétérinaire dit
     * qu'il l'y a saisi, et la déclaration n'est plus à faire ici.
     */
    public function enteredOnWeb(AntibioticDeclaration $declaration): AntibioticDeclarationResource
    {
        Gate::authorize('prescrire');
        DB::transaction(function () use ($declaration): void {
            $locked = AntibioticDeclaration::query()->whereKey($declaration->id)->lockForUpdate()->firstOrFail();
            // Un traitement oral de groupe ; ou, la clinique en variante 2, toute déclaration pas encore partie
            // (elle la saisit sur le site de l'OSAV : un retour en variante 1 ne la renverra pas)
            $isWebOnly = app(DeclarationRules::class)->webOnlyReason($locked->load('lines')) !== null;
            if (! $locked->status->isEditable() || ! ($isWebOnly || IsabvConnection::activeFor($locked->clinique_id) === null)) {
                throw ValidationException::withMessages(['declaration' => 'Seul un traitement oral de groupe, ou une déclaration d’une clinique en variante 2, se dit saisi sur le site de l’OSAV.']);
            }
            // Prise par l'envoi au passage en variante 2 : le vétérinaire l'a vérifiée sur le site, elle n'est plus relancée
            $locked->update(['status' => DeclarationStatus::EnteredOnWeb, 'is_outcome_unknown' => false]);
        });

        return $this->show($declaration->fresh());
    }

    /**
     * Une déclaration à désactiver que Veterinary ne peut pas désactiver
     * (envoyée sous un ancien complément) : le vétérinaire l'a désactivée
     * dans l'application web de l'OSAV, sa correction peut partir.
     */
    public function deactivatedOnWeb(AntibioticDeclaration $declaration): AntibioticDeclarationResource
    {
        Gate::authorize('prescrire');
        DB::transaction(function () use ($declaration): void {
            $locked = AntibioticDeclaration::query()->whereKey($declaration->id)->lockForUpdate()->firstOrFail();
            // Seulement une déclaration partie sous un autre complément que celui de la clinique, que Veterinary ne peut pas désactiver
            if (! $locked->isDeactivatedOnlyOnWeb()) {
                throw ValidationException::withMessages(['declaration' => 'Seule une déclaration que Veterinary ne peut pas désactiver se dit désactivée sur le site de l’OSAV.']);
            }
            $locked->update(['status' => DeclarationStatus::Deactivated, 'deactivated_at' => now()]);
            // Sa correction, si elle attendait
            AntibioticDeclaration::query()->where('replaces_id', $locked->id)->where('status', DeclarationStatus::Ready)->pluck('id')
                ->each(fn (int $id) => DB::afterCommit(fn () => SendAntibioticDeclaration::dispatch($id)));
        });

        return $this->show($declaration->fresh());
    }

    /** Le nombre de consultations compté pour l'année en cours et la précédente, et ce qui est parti */
    public function consultations(): JsonResponse
    {
        return response()->json(['data' => app(ConsultationCounts::class)->overview($this->clinique())]);
    }

    /** Envoie le nombre de consultations d'une année, les quatre espèces ; un nouvel envoi remplace l'ancien */
    public function sendConsultations(Request $request): JsonResponse
    {
        Gate::authorize('prescrire');
        $clinique = $this->clinique();
        $current = (int) CarbonImmutable::now($clinique->fuseau_horaire ?: 'Europe/Zurich')->year;
        $year = (int) $request->validate(['year' => ['required', 'integer', Rule::in([$current - 1, $current])]], [
            'year.in' => 'L’OSAV n’accepte que l’année en cours et l’année écoulée.',
        ])['year'];
        try {
            app(ConsultationCounts::class)->send($clinique, $year, $this->client($clinique));
        } catch (IsabvTokenRefused) {
            $this->tokenRefused();
        } catch (IsabvUnavailable) {
            abort(503, 'Le serveur de l’OSAV ne répond pas : réessayez dans un moment.');
        }

        return $this->consultations();
    }

    /** Les déclarations AV et FZL que l'OSAV a désactivées comme doublons (directives, ch. 5.1.4) */
    public function duplicates(): JsonResponse
    {
        $client = $this->client($this->clinique());
        try {
            $numbers = $client->duplicates();
        } catch (IsabvTokenRefused) {
            $this->tokenRefused();
        } catch (IsabvUnavailable) {
            abort(503, 'Le serveur de l’OSAV ne répond pas : réessayez dans un moment.');
        }
        $known = AntibioticDeclaration::query()->with(['patient', 'lines.preparation', 'lines.produit', 'employe' => fn ($q) => $q->withoutGlobalScope('clinique'), 'source'])
            ->whereIn('recipe_number', $numbers)->get();

        return response()->json(['data' => AntibioticDeclarationResource::collection($known)]);
    }

    public function reactivate(Request $request): JsonResponse
    {
        Gate::authorize('prescrire');
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']])['ids'];
        $numbers = AntibioticDeclaration::query()->whereIn('id', $ids)->pluck('recipe_number')->all();
        if ($numbers === []) {
            throw ValidationException::withMessages(['ids' => 'Aucune de ces déclarations n’est de la clinique.']);
        }
        try {
            $client = $this->client($this->clinique());
            // Seulement celles que l'OSAV tient pour des doublons
            $numbers = array_values(array_intersect($numbers, $client->duplicates()));
            if ($numbers === []) {
                throw ValidationException::withMessages(['ids' => 'L’OSAV ne tient aucune de ces déclarations pour un doublon.']);
            }
            $result = $client->reactivate($numbers);
        } catch (IsabvTokenRefused) {
            $this->tokenRefused();
        } catch (IsabvUnavailable) {
            abort(503, 'Le serveur de l’OSAV ne répond pas : réessayez dans un moment.');
        }
        if (! $result->isOk) {
            throw ValidationException::withMessages(['ids' => $result->message ?? 'L’OSAV refuse de les réactiver.']);
        }
        // Réactivées : le message du doublon ne vaut plus
        AntibioticDeclaration::query()->whereIn('recipe_number', $numbers)->update(['osav_message' => null]);

        return response()->json(['data' => ['reactivated' => count($numbers)]]);
    }

    private function client(Clinique $clinique): IsabvClient
    {
        $connection = IsabvConnection::activeFor($clinique->id);
        $uid = DeclarationXml::uid($clinique);
        if ($connection === null || ! $connection->sendsThroughVeterinary() || $uid === null) {
            throw ValidationException::withMessages(['connection' => 'La clinique ne déclare pas par Veterinary : réglez le SI ABV dans Réglages, Clinique.']);
        }

        return IsabvClient::for($uid, $connection->appendix, (string) $connection->plainToken());
    }

    private function tokenRefused(): never
    {
        IsabvConnection::query()->where('clinique_id', $this->contexte->cliniqueId())->update(['verified_at' => null]);
        throw ValidationException::withMessages(['connection' => 'L’OSAV refuse le token de la clinique : recollez-le dans Réglages, SI ABV.']);
    }

    /** @return array<string, mixed> */
    private function connectionState(): array
    {
        $connection = IsabvConnection::query()->where('clinique_id', $this->contexte->cliniqueId())->first();

        return ['variant' => $connection?->variant, 'is_sending' => $connection?->sendsThroughVeterinary() ?? false];
    }

    private function clinique(): Clinique
    {
        return Clinique::query()->with('societe')->findOrFail((int) $this->contexte->cliniqueId());
    }
}
