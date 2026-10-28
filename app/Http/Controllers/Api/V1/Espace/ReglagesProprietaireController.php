<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Espace\ModifierPreferencesRequest;
use App\Models\Client;
use App\Models\CommandeBoutique;
use App\Models\JetonApi;
use App\Models\MessageClientele;
use App\Models\Patient;
use App\Models\PreferencesProprietaire;
use App\Models\RendezVous;
use App\Services\AccueilProprietaire;
use App\Services\Rappels\RappelsProprietaires;
use App\Support\Telephone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Réglages de l'espace client : préférences (notifications, langue,
 * statistiques), appareils connectés (jetons du compte), notifications
 * dérivées de l'accueil avec leur état lu, export des données du compte.
 */
class ReglagesProprietaireController extends Controller
{
    public function preferences(ContexteProprietaire $contexte): JsonResponse
    {
        return response()->json(['data' => $this->preferencesArray(PreferencesProprietaire::pour($contexte->user()), $contexte->user()->id)]);
    }

    public function modifierPreferences(ModifierPreferencesRequest $request, ContexteProprietaire $contexte): JsonResponse
    {
        // Les SMS « avec son mobile » : sans mobile, ils ne s'activent pas,
        // d'où qu'il les demande (configuration ou réglages)
        if ($request->has('rappels_sms') && $request->boolean('rappels_sms')) {
            $fiche = $contexte->clients()->first();
            if (Telephone::mobile($fiche->tel ?? $contexte->user()->tel) === null) {
                throw ValidationException::withMessages(['rappels_sms' => 'Indiquez d’abord votre numéro de mobile : les SMS partent vers un numéro en 07x, ou étranger avec son indicatif.']);
            }
        }
        $preferences = PreferencesProprietaire::pour($contexte->user());
        $preferences->update($request->validated());
        // Les rappels choisis : la ligne « Vos rappels » de sa configuration est faite
        if ($request->has('rappels_email') || $request->has('rappels_sms')) {
            $preferences->forceFill(['rappels_choisis_le' => now()])->save();
        }
        // Rappels par e-mail réactivés : un désabonnement par le lien d'un e-mail est levé aussi
        if ($request->has('rappels_email') && $request->boolean('rappels_email')) {
            Client::withoutGlobalScopes()->where('user_id', $contexte->user()->id)->update(['rappels_email' => true]);
        }

        return response()->json(['data' => $this->preferencesArray($preferences->fresh(), $contexte->user()->id)]);
    }

    public function appareils(Request $request, ContexteProprietaire $contexte): JsonResponse
    {
        $courant = $request->user()->currentAccessToken();

        $jetons = $contexte->user()->tokens()
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $jetons->map(fn (PersonalAccessToken $jeton): array => [
            'id' => $jeton->id,
            'nom' => $jeton->name,
            'cree_le' => $jeton->created_at?->toIso8601String(),
            'dernier_usage_le' => $jeton->last_used_at?->toIso8601String(),
            'est_courant' => $courant instanceof JetonApi && $courant->id === $jeton->id,
        ])->all()]);
    }

    /** Ferme toutes les autres sessions du compte ; renvoie le nombre fermé. */
    public function deconnecterAutres(Request $request, ContexteProprietaire $contexte): JsonResponse
    {
        $courant = $request->user()->currentAccessToken();

        $fermes = $contexte->user()->tokens()
            ->when($courant instanceof JetonApi, fn ($q) => $q->where('id', '!=', $courant->id))
            ->delete();

        return response()->json(['data' => ['fermes' => $fermes]]);
    }

    /**
     * Notifications (cloche du proto), dérivées des mêmes blocs que
     * l'accueil : rappels de vaccins, devis à signer, commandes prêtes,
     * messages non lus — avec l'état lu conservé dans les préférences.
     */
    public function notifications(ContexteProprietaire $contexte, AccueilProprietaire $accueil): JsonResponse
    {
        $lues = PreferencesProprietaire::pour($contexte->user())->notifications_lues ?? [];
        $blocs = $accueil->composer($contexte);
        $items = [];

        foreach ($blocs['rappels'] as $r) {
            $items[] = [
                'cle' => 'vaccin:'.$r['vaccination_id'],
                'titre' => $r['est_en_retard']
                    ? sprintf('Vaccin %s de %s en retard', $r['vaccin'], $r['patient']['nom'])
                    : sprintf('Rappel %s de %s : J-%d', $r['vaccin'], $r['patient']['nom'], $r['jours']),
                'sous_titre' => $r['est_en_retard'] ? 'Prenez rendez-vous' : 'Réservez un créneau vaccination',
                'cible' => 'rendez-vous',
            ];
        }

        foreach ($blocs['a_faire'] as $t) {
            if ($t['type'] === 'devis') {
                $items[] = ['cle' => 'devis:'.$t['devis_id'], 'titre' => 'Devis à signer', 'sous_titre' => $t['libelle'], 'cible' => 'documents'];
            } elseif ($t['type'] === 'commande') {
                $items[] = ['cle' => 'commande:'.$t['commande_id'], 'titre' => 'Commande prête au retrait', 'sous_titre' => $t['libelle'], 'cible' => 'commandes'];
            } elseif ($t['type'] === 'message') {
                $items[] = ['cle' => 'messages:'.$blocs['messages_non_lus'], 'titre' => $t['libelle'], 'sous_titre' => 'Ouvrez vos messages', 'cible' => 'messages'];
            }
        }

        $items = array_map(fn (array $i): array => [...$i, 'lue' => in_array($i['cle'], $lues, true)], $items);

        return response()->json(['data' => [
            'notifications' => $items,
            'non_lues' => count(array_filter($items, fn (array $i): bool => ! $i['lue'])),
        ]]);
    }

    public function marquerLues(Request $request, ContexteProprietaire $contexte): JsonResponse
    {
        $donnees = $request->validate(['cles' => ['required', 'array', 'max:200'], 'cles.*' => ['string', 'max:64']]);
        $preferences = PreferencesProprietaire::pour($contexte->user());
        $lues = array_values(array_unique(array_merge($preferences->notifications_lues ?? [], $donnees['cles'])));
        $preferences->update(['notifications_lues' => array_slice($lues, -200)]);

        return response()->json(['data' => ['lues' => count($lues)]]);
    }

    /** Export des données du compte (RGPD) : un document JSON à télécharger. */
    public function export(ContexteProprietaire $contexte, AccueilProprietaire $accueil): JsonResponse
    {
        $user = $contexte->user();

        return response()->json(['data' => [
            'exporte_le' => now()->toIso8601String(),
            'compte' => ['nom' => $user->name, 'email' => $user->email, 'cree_le' => $user->created_at?->toIso8601String(),
                // Ses coordonnées gardées sur le compte (28.10.2026)
                'tel' => $user->tel, 'rue' => $user->rue, 'npa' => $user->npa, 'localite' => $user->localite],
            'fiches' => $contexte->clients()->map(fn ($c): array => [
                'organisation' => $c->organisation->nom, 'prenom' => $c->prenom, 'nom' => $c->nom, 'tel' => $c->tel,
                'rue' => $c->rue, 'npa' => $c->npa, 'localite' => $c->localite,
            ])->values()->all(),
            'animaux' => Patient::withoutGlobalScopes()->with(['vaccinations', 'visites', 'pesees'])
                ->whereIn('client_id', $contexte->clientIds())->get()
                ->map(fn ($p): array => [
                    'nom' => $p->nom, 'espece' => $p->espece->libelle(), 'race' => $p->race, 'sexe' => $p->sexe->value,
                    'ne_le' => $p->ne_le?->toDateString(), 'puce' => $p->puce,
                    'vaccinations' => $p->vaccinations->map(fn ($v): array => ['nom' => $v->nom, 'injectee_le' => $v->injectee_le->toDateString(), 'echeance_le' => $v->echeance_le?->toDateString()])->all(),
                    'visites' => $p->visites->map(fn ($v): array => ['visite_le' => $v->visite_le->toDateString(), 'motif' => $v->motif])->all(),
                    'pesees' => $p->pesees->map(fn ($v): array => ['pesee_le' => $v->pesee_le->toDateString(), 'poids_kg' => (float) $v->poids_kg])->all(),
                ])->all(),
            'rendez_vous' => RendezVous::withoutGlobalScopes()->with('clinique')
                ->whereIn('client_id', $contexte->clientIds())->orderBy('starts_at')->get()
                ->map(fn ($r): array => ['debute_le' => $r->starts_at->toIso8601String(), 'motif' => $r->reason, 'statut' => $r->status->value, 'clinique' => $r->clinique->nom, 'animal' => $r->patient_name])->all(),
            'commandes' => CommandeBoutique::withoutGlobalScopes()->with('lignes.produit')
                ->whereIn('client_id', $contexte->clientIds())->get()
                ->map(fn ($c): array => ['numero' => $c->numero, 'passee_le' => $c->passee_le->toIso8601String(), 'statut' => $c->statut->value, 'total_ttc' => round($c->totalTtc(), 2)])->all(),
            'messages' => MessageClientele::query()->with('clinique')->where('user_id', $user->id)->orderBy('created_at')->get()
                ->map(fn ($m): array => ['clinique' => $m->clinique->nom, 'auteur' => $m->auteur, 'texte' => $m->texte, 'envoye_le' => $m->created_at?->toIso8601String()])->all(),
            'preferences' => $this->preferencesArray(PreferencesProprietaire::pour($user), $user->id),
        ]]);
    }

    /** @return array<string, mixed> */
    private function preferencesArray(PreferencesProprietaire $p, int $userId): array
    {
        return [
            // Ce qui part vraiment : une fiche désabonnée par le lien d'un e-mail
            // (avant d'être rattachée au compte) bloque aussi ses rappels
            'rappels_email' => $p->rappels_email && ! Client::withoutGlobalScopes()->where('user_id', $userId)->where('rappels_email', false)->exists(),
            // Pas encore choisi : la case cochée par une clinique sur sa fiche vaut (décision de Jim, 28.10.2026)
            'rappels_sms' => RappelsProprietaires::choixSms($p) ?? Client::withoutGlobalScopes()->where('user_id', $userId)->where('rappels_sms', true)->exists(),
            'reponses_clinique' => $p->reponses_clinique,
            'nouveautes_boutique' => $p->nouveautes_boutique,
            'statistiques_anonymes' => $p->statistiques_anonymes,
            'langue' => $p->langue,
        ];
    }
}
