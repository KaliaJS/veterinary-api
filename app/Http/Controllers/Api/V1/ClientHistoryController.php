<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatutDemandeRendezVous;
use App\Enums\StatutFacture;
use App\Enums\StatutRendezVous;
use App\Http\Controllers\Api\V1\Espace\RendezVousProprietaireController;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\Facture;
use App\Models\MessageClientele;
use App\Models\Patient;
use App\Models\RappelEnvoye;
use App\Models\RendezVous;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * L'historique d'un propriétaire, sur un fil (fenêtre propriétaire,
 * proposition A, 03.11.2026) : ses rendez-vous, ses factures (avec ce
 * qui reste à payer), ses messages, les rappels envoyés, ses demandes en
 * ligne ; ce qui vient en tête, puis du plus récent au plus ancien, par
 * pages de trente (`avant` : `meta.suivant` de la page reçue, inclus) ;
 * `type` n'en garde qu'une sorte.
 */
class ClientHistoryController extends Controller
{
    private const PAR_PAGE = 30;

    private const TYPES = ['rdv', 'facture', 'rappel', 'demande', 'message'];

    public function __invoke(Request $request, Client $client): JsonResponse
    {
        $this->authorize('view', $client);
        $request->validate([
            'avant' => ['sometimes', 'nullable', 'date'],
            'sauter' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'type' => ['sometimes', 'nullable', Rule::in(self::TYPES)],
        ]);
        $avant = $request->filled('avant') ? CarbonImmutable::parse((string) $request->string('avant'))->utc() : null;
        // Curseur : `avant` (inclusif) et `sauter`, le nombre d'éléments de
        // cette seconde déjà servis ; même cent rappels envoyés à la même
        // seconde se lisent tous, page après page
        $sauter = $avant === null ? 0 : $request->integer('sauter');
        $type = $request->filled('type') ? (string) $request->string('type') : null;
        $veut = fn (string $t): bool => $type === null || $type === $t;
        $cliniques = Clinique::withoutGlobalScopes()->whereHas('societe', fn ($q) => $q->where('organisation_id', $client->organisation_id))->pluck('id');
        $animaux = Patient::withoutGlobalScopes()->where('client_id', $client->id)->pluck('nom', 'id');
        $limite = self::PAR_PAGE + 1 + $sauter;

        // Ordre total : la date, puis la sorte et le numéro dans la même seconde
        $passes = collect([
            ...($veut('rdv') ? $this->rendezVous($client, $animaux, $cliniques, $avant, $limite) : []),
            ...($veut('facture') ? $this->factures($client, $avant, $limite) : []),
            ...($veut('rappel') ? $this->rappels($client, $animaux, $avant, $limite) : []),
            ...($veut('demande') ? $this->demandes($client, $animaux, $avant, $limite) : []),
            ...($veut('message') ? $this->messages($client, $cliniques, $avant, $limite) : []),
        ])
            ->map(fn (array $x): array => [...$x, '_t' => CarbonImmutable::parse($x['date'])->utc()->getTimestamp()])
            ->sort(fn (array $a, array $b): int => [$b['_t'], $b['type'], $b['id']] <=> [$a['_t'], $a['type'], $a['id']])
            ->values();
        if ($avant !== null) {
            // Ceux de la seconde `avant` déjà servis viennent en premier
            $passes = $passes->slice(min($sauter, $passes->takeWhile(fn (array $x): bool => $x['_t'] === $avant->getTimestamp())->count()))->values();
        }
        $page = $passes->take(self::PAR_PAGE)->values();
        $suivant = null;
        $sauterSuivant = 0;
        if ($passes->count() > self::PAR_PAGE) {
            $t = $passes[self::PAR_PAGE]['_t'];
            $suivant = CarbonImmutable::createFromTimestampUTC($t)->toIso8601String();
            $sauterSuivant = $page->filter(fn (array $x): bool => $x['_t'] === $t)->count()
                + ($avant !== null && $t === $avant->getTimestamp() ? $sauter : 0);
        }
        $page = $page->map(fn (array $x): array => array_diff_key($x, ['_t' => true]));

        // Ce qui vient : les rendez-vous à venir, en tête de la première page seulement
        $aVenir = $avant === null && $veut('rdv') ? $this->aVenir($client, $animaux, $cliniques) : [];

        return response()->json([
            'data' => [...$aVenir, ...$page->all()],
            'meta' => ['suivant' => $suivant, 'sauter' => $sauterSuivant],
        ]);
    }

    /**
     * Les rendez-vous de la fiche et de ses animaux (pas ceux d'un propriétaire précédent).
     *
     * @param  Collection<int, string>  $animaux
     * @param  Collection<int, int>  $cliniques
     */
    private function rendezVousRequete(Client $client, Collection $animaux, Collection $cliniques)
    {
        return RendezVous::withoutGlobalScopes()
            ->with('lignePlanning')
            ->whereIn('clinique_id', $cliniques)
            ->where('type', '!=', 'tampon')
            ->where(fn ($q) => $q->where('client_id', $client->id)
                ->orWhere(fn ($q) => RendezVousProprietaireController::deSesAnimaux($q, $animaux->keys()->all())));
    }

    /**
     * @param  Collection<int, string>  $animaux
     * @param  Collection<int, int>  $cliniques
     * @return list<array<string, mixed>>
     */
    private function aVenir(Client $client, Collection $animaux, Collection $cliniques): array
    {
        return $this->rendezVousRequete($client, $animaux, $cliniques)
            ->where('starts_at', '>=', now())
            ->whereIn('status', [StatutRendezVous::Prevu, StatutRendezVous::Salle, StatutRendezVous::Cours])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (RendezVous $r): array => $this->ligneRendezVous($r, $animaux, true))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, string>  $animaux
     * @param  Collection<int, int>  $cliniques
     * @return list<array<string, mixed>>
     */
    private function rendezVous(Client $client, Collection $animaux, Collection $cliniques, ?CarbonImmutable $avant, int $limite): array
    {
        return $this->rendezVousRequete($client, $animaux, $cliniques)
            ->where(fn ($q) => $q->where('starts_at', '<', now())->orWhereNotIn('status', [StatutRendezVous::Prevu, StatutRendezVous::Salle, StatutRendezVous::Cours]))
            ->when($avant, fn ($q) => $q->where('starts_at', '<=', $avant))
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit($limite)
            ->get()
            ->map(fn (RendezVous $r): array => $this->ligneRendezVous($r, $animaux, false))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, string>  $animaux
     * @return array<string, mixed>
     */
    private function ligneRendezVous(RendezVous $r, Collection $animaux, bool $aVenir): array
    {
        $statut = match ($r->status) {
            StatutRendezVous::Fini => 'Terminé',
            StatutRendezVous::Noshow => 'Non venu',
            StatutRendezVous::Annule => 'Annulé',
            default => $aVenir ? 'Prévu' : 'Passé',
        };

        return [
            'type' => 'rdv', 'id' => $r->id, 'date' => $r->starts_at->toIso8601String(), 'a_venir' => $aVenir,
            'animal' => $animaux[$r->patient_id] ?? $r->patient_name,
            'titre' => trim(($animaux[$r->patient_id] ?? $r->patient_name).' · '.($r->reason ?: 'Rendez-vous'), ' ·'),
            'detail' => implode(' · ', array_filter([$r->lignePlanning?->nom, $statut])),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function factures(Client $client, ?CarbonImmutable $avant, int $limite): array
    {
        // Les montants : seulement pour qui a le droit de la facturation
        if (! Gate::allows('facturation')) {
            return [];
        }

        return Facture::withoutGlobalScopes()
            ->with(['lignes', 'settlements'])
            ->where('client_id', $client->id)
            ->where('statut', '!=', StatutFacture::Annulee)
            ->when($avant, fn ($q) => $q->where('emise_le', '<=', $avant))
            ->orderByDesc('emise_le')
            ->orderByDesc('id')
            ->limit($limite)
            ->get()
            ->map(function (Facture $f): array {
                $reste = round($f->resteAPayer(), 2);

                return [
                    'type' => 'facture', 'id' => $f->id, 'date' => $f->emise_le->toIso8601String(), 'a_venir' => false,
                    'animal' => $f->patient_name,
                    'titre' => 'Facture '.$f->numero.' · '.chf($f->totalTtc()),
                    'detail' => $f->statut === StatutFacture::Payee ? 'Payée' : 'À régler',
                    'facture_id' => $f->id,
                    'reste_a_payer' => $f->statut === StatutFacture::Due ? $reste : 0,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, string>  $animaux
     * @return list<array<string, mixed>>
     */
    private function rappels(Client $client, Collection $animaux, ?CarbonImmutable $avant, int $limite): array
    {
        return RappelEnvoye::withoutGlobalScopes()
            ->where('client_id', $client->id)
            ->where('statut', 'envoye')
            ->whereNotNull('envoye_le')
            ->when($avant, fn ($q) => $q->where('envoye_le', '<=', $avant))
            ->orderByDesc('envoye_le')
            ->orderByDesc('id')
            ->limit($limite)
            ->get()
            ->map(fn (RappelEnvoye $r): array => [
                'type' => 'rappel', 'id' => $r->id, 'date' => $r->envoye_le->toIso8601String(), 'a_venir' => false,
                'animal' => $animaux[$r->patient_id] ?? null,
                'titre' => 'Rappel : '.$r->libelle.(isset($animaux[$r->patient_id]) ? ' de '.$animaux[$r->patient_id] : ''),
                'detail' => match ($r->canal) {
                    'sms' => 'Envoyé par SMS',
                    'email' => 'Envoyé par e-mail',
                    default => 'Envoyé',
                },
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, string>  $animaux
     * @return list<array<string, mixed>>
     */
    private function demandes(Client $client, Collection $animaux, ?CarbonImmutable $avant, int $limite): array
    {
        return DemandeRendezVous::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('client_id', $client->id)->orWhere(fn ($q) => $q->whereIn('patient_id', $animaux->keys())->whereNull('client_id')))
            ->when($avant, fn ($q) => $q->where('created_at', '<=', $avant))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limite)
            ->get()
            ->map(fn (DemandeRendezVous $d): array => [
                'type' => 'demande', 'id' => $d->id, 'date' => $d->created_at->toIso8601String(), 'a_venir' => false,
                'animal' => $animaux[$d->patient_id] ?? $d->patient_name,
                'titre' => 'Demande en ligne : « '.$d->reason.' »',
                'detail' => match ($d->statut) {
                    StatutDemandeRendezVous::Acceptee => 'Acceptée',
                    StatutDemandeRendezVous::Refusee => 'Refusée',
                    StatutDemandeRendezVous::Proposee => 'Créneaux proposés',
                    default => 'À traiter',
                },
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, int>  $cliniques
     * @return list<array<string, mixed>>
     */
    private function messages(Client $client, Collection $cliniques, ?CarbonImmutable $avant, int $limite): array
    {
        if ($client->user_id === null) {
            return [];
        }

        return MessageClientele::query()
            ->where('user_id', $client->user_id)
            ->whereIn('clinique_id', $cliniques)
            ->when($avant, fn ($q) => $q->where('created_at', '<=', $avant))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limite)
            ->get()
            ->map(fn (MessageClientele $m): array => [
                'type' => 'message', 'id' => $m->id, 'date' => $m->created_at->toIso8601String(), 'a_venir' => false,
                'animal' => null,
                'titre' => '« '.mb_strimwidth($m->texte, 0, 120, '…').' »',
                'detail' => $m->auteur === 'clinique' ? 'Message de la clinique' : 'Message dans son espace',
            ])
            ->values()
            ->all();
    }
}
