<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\StatutEnregistrement;
use App\Enums\StatutPartie;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Transcription\TerminerEnregistrementRequest;
use App\Http\Resources\V1\EnregistrementConsultationResource;
use App\Jobs\RedigerCompteRendu;
use App\Jobs\TranscrirePartie;
use App\Models\EnregistrementConsultation;
use App\Models\Organisation;
use App\Models\RendezVous;
use App\Services\Transcription\ConsommationTranscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Enregistrement d'une consultation (19.10.2026), adossé à son
 * rendez-vous : démarrer (ou reprendre après une pause), terminer (le
 * nombre de parties est alors connu, la rédaction part dès la dernière
 * transcrite), relancer après une erreur, abandonner. L'audio arrive par
 * PartieEnregistrementController. Niveau organisation, comme le compte
 * rendu : le rendez-vous est résolu parmi les cliniques de l'organisation.
 */
class EnregistrementConsultationController extends Controller
{
    public function __construct(private ConsommationTranscription $consommation) {}

    /** Le dernier enregistrement du rendez-vous. */
    public function afficher(string $rendezVous): EnregistrementConsultationResource
    {
        $this->authorize('viewAny', EnregistrementConsultation::class);

        return new EnregistrementConsultationResource(
            $this->dernier($this->rendezVousDeLOrganisation($rendezVous)) ?? abort(404),
        );
    }

    /**
     * Démarre un enregistrement, ou rouvre le dernier tant que le compte
     * rendu n'est pas validé (une nouvelle partie s'ajoute aux autres). Le
     * plafond ne bloque que le début d'un enregistrement : une consultation
     * en cours se termine toujours.
     */
    public function demarrer(Request $request, string $rendezVous): EnregistrementConsultationResource
    {
        $this->authorize('create', EnregistrementConsultation::class);

        $rendezVous = $this->rendezVousDeLOrganisation($rendezVous);
        $dernier = $this->dernier($rendezVous);

        if ($dernier?->statut === StatutEnregistrement::Enregistrement) {
            return new EnregistrementConsultationResource($dernier);
        }
        if (in_array($dernier?->statut, [StatutEnregistrement::Transcription, StatutEnregistrement::Redaction], true)) {
            throw ValidationException::withMessages(['enregistrement' => 'Le compte rendu de cet enregistrement est en cours de rédaction. Patientez un instant.']);
        }

        $refus = $this->consommation->refus($this->organisation());
        if ($refus) {
            throw ValidationException::withMessages(['transcription' => $refus]);
        }

        if ($dernier && $dernier->statut !== StatutEnregistrement::Valide) {
            $dernier->forceFill([
                'statut' => StatutEnregistrement::Enregistrement,
                'erreur' => null,
                'parties_attendues' => null,
                'brouillon' => null,
                'redige_le' => null,
            ])->save();

            return new EnregistrementConsultationResource($dernier->load('parties'));
        }

        $enregistrement = EnregistrementConsultation::query()->create([
            'clinique_id' => app(ContexteClinique::class)->cliniqueId(),
            'rendez_vous_id' => $rendezVous->id,
            'user_id' => $request->user()->id,
        ]);

        return new EnregistrementConsultationResource($enregistrement->load('parties'));
    }

    public function terminer(TerminerEnregistrementRequest $request, EnregistrementConsultation $enregistrement): EnregistrementConsultationResource
    {
        $this->authorize('create', EnregistrementConsultation::class);

        if ($enregistrement->statut !== StatutEnregistrement::Enregistrement) {
            throw ValidationException::withMessages(['enregistrement' => 'Cet enregistrement est déjà terminé.']);
        }

        $parties = (int) $request->validated('parties');
        if ($parties < (int) $enregistrement->parties()->max('numero')) {
            throw ValidationException::withMessages(['parties' => 'Des parties déjà envoyées manquent au compte.']);
        }

        $enEchec = $enregistrement->parties()->where('statut', StatutPartie::Erreur)->exists();
        $enregistrement->forceFill([
            'parties_attendues' => $parties,
            'statut' => $enEchec ? StatutEnregistrement::Erreur : StatutEnregistrement::Transcription,
            'erreur' => $enEchec ? ($enregistrement->erreur ?? 'transcription') : null,
        ])->save();

        RedigerCompteRendu::siPret($enregistrement);

        return new EnregistrementConsultationResource($enregistrement->refresh()->load('parties'));
    }

    /** Après une erreur : retranscrit les parties en échec (si leur audio est encore là), ou relance la rédaction. */
    public function relancer(EnregistrementConsultation $enregistrement): EnregistrementConsultationResource
    {
        $this->authorize('create', EnregistrementConsultation::class);

        if ($enregistrement->statut !== StatutEnregistrement::Erreur) {
            throw ValidationException::withMessages(['enregistrement' => 'Rien à relancer.']);
        }
        if ($enregistrement->erreur === 'redactions') {
            throw ValidationException::withMessages(['enregistrement' => 'Le compte rendu a déjà été rédigé plusieurs fois : écrivez-le à partir de la transcription.']);
        }

        $enEchec = $enregistrement->parties()->where('statut', StatutPartie::Erreur)->get();
        if ($enEchec->contains(fn ($partie) => ! $partie->fichier)) {
            throw ValidationException::withMessages(['enregistrement' => 'L’audio d’une partie n’est plus disponible : la transcription ne peut pas être relancée.']);
        }

        $enregistrement->forceFill(['statut' => StatutEnregistrement::Transcription, 'erreur' => null])->save();
        foreach ($enEchec as $partie) {
            $partie->forceFill(['statut' => StatutPartie::Recue])->save();
            TranscrirePartie::dispatch($partie->id);
        }

        RedigerCompteRendu::siPret($enregistrement);

        return new EnregistrementConsultationResource($enregistrement->refresh()->load('parties'));
    }

    /**
     * Abandon (propriétaire qui se ravise, essai) : audio et texte effacés.
     * Un compte rendu validé reste. La fiche reste aussi, vide (24.10.2026) :
     * effacée, elle emporterait les secondes transcrites et les rédactions,
     * et les plafonds repartiraient à zéro.
     */
    public function supprimer(EnregistrementConsultation $enregistrement): Response
    {
        $this->authorize('create', EnregistrementConsultation::class);

        $valide = ValidationException::withMessages(['enregistrement' => 'Le compte rendu de cet enregistrement est déjà validé.']);
        if ($enregistrement->statut === StatutEnregistrement::Valide) {
            throw $valide;
        }

        Storage::disk('prive')->deleteDirectory($enregistrement->dossierAudio());
        DB::transaction(function () use ($enregistrement, $valide): void {
            // Fiche verrouillée d'abord : une transcription ou une rédaction qui
            // se termine au même instant voit l'abandon et n'écrit pas de texte
            $verrouille = EnregistrementConsultation::withoutGlobalScopes()->whereKey($enregistrement->id)->lockForUpdate()->firstOrFail();
            if ($verrouille->statut === StatutEnregistrement::Valide) {
                throw $valide;
            }

            $verrouille->parties()->update(['segments' => null, 'fichier' => null]);
            $verrouille->forceFill(['statut' => StatutEnregistrement::Abandonne, 'erreur' => null, 'brouillon' => null])->save();
        });

        return response()->noContent();
    }

    private function dernier(RendezVous $rendezVous): ?EnregistrementConsultation
    {
        return EnregistrementConsultation::query()
            ->where('rendez_vous_id', $rendezVous->id)
            // Un abandonné ne se reprend pas : le suivant repart d'une fiche neuve
            ->where('statut', '!=', StatutEnregistrement::Abandonne->value)
            ->latest('id')
            ->with('parties')
            ->first();
    }

    private function organisation(): Organisation
    {
        return Organisation::query()->findOrFail(app(ContexteClinique::class)->organisationId());
    }

    /**
     * Le scope clinique du rendez-vous est levé mais la requête reste
     * bornée à l'organisation du jeton : hors organisation, 404.
     */
    private function rendezVousDeLOrganisation(string $id): RendezVous
    {
        return RendezVous::withoutGlobalScope('clinique')
            ->whereHas('clinique.societe', fn (Builder $societe) => $societe
                ->where('organisation_id', app(ContexteClinique::class)->organisationId()))
            ->findOrFail($id);
    }
}
