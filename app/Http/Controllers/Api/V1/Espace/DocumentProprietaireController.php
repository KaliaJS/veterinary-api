<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Enums\CanalDevis;
use App\Enums\StatutDevis;
use App\Enums\StatutOrdonnance;
use App\Events\DevisMisAJour;
use App\Events\RenouvellementOrdonnanceDemande;
use App\Http\Controllers\Controller;
use App\Models\Devis;
use App\Models\Document;
use App\Models\Ordonnance;
use App\Services\DocumentsProprietaire;
use App\Support\TempsReel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documents & factures du propriétaire : liste composée, signature
 * électronique d'un devis du portail (l'acceptation devient un geste du
 * propriétaire — même effet que POST /devis/{id}/accepter côté
 * clinique), demande de renouvellement d'une ordonnance active.
 */
class DocumentProprietaireController extends Controller
{
    public function index(ContexteProprietaire $contexte, DocumentsProprietaire $documents): JsonResponse
    {
        return response()->json(['data' => $documents->lister($contexte)]);
    }

    /**
     * Un PDF ajouté au dossier que la clinique a partagé (28.10.2026) : de
     * ses animaux seulement, jamais un document interne.
     */
    public function fichier(ContexteProprietaire $contexte, int $document): StreamedResponse
    {
        $piece = Document::query()->withoutGlobalScopes()
            ->whereIn('patient_id', $contexte->patientIds())
            ->where('visible_proprietaire', true)
            ->where('chemin', 'like', DocumentsProprietaire::DOSSIER_PDF.'%')
            ->findOrFail($document);
        abort_unless(Storage::disk('prive')->exists($piece->chemin), 404);

        return Storage::disk('prive')->download($piece->chemin, Str::slug($piece->nom).'.pdf', ['Content-Type' => 'application/pdf']);
    }

    /**
     * Le propriétaire accepte le devis après l'avoir lu (31.10.2026, fenêtre
     * choisie par Jim) : il coche « J'ai lu le devis et j'accepte… »
     * (`accepte`), la signature porte le nom de sa fiche client, la clinique
     * le voit tout de suite.
     */
    public function signerDevis(Request $request, ContexteProprietaire $contexte, int $devisId): JsonResponse
    {
        $request->validate(['accepte' => ['accepted']], ['accepte.accepted' => 'Cochez « J’ai lu le devis et j’accepte les soins prévus et ses conditions ».']);
        $devis = $this->devisEnAttente($contexte, $devisId);
        $client = $devis->patient->client;
        $nom = trim(($client->prenom ?? '').' '.($client->nom ?? '')) ?: $contexte->user()->name;

        $this->repondre($devis, [
            'statut' => StatutDevis::Accepte,
            'signe_le' => now(),
            'signe_par' => 'Signature électronique de '.$nom.', espace client',
        ]);
        // Reverb en panne : la réponse du client reste enregistrée, l'écran de la clinique relira au retour du WebSocket
        TempsReel::diffuser(fn () => DevisMisAJour::dispatch($devis));

        return response()->json(['data' => ['id' => $devis->id, 'statut' => $devis->statut->value, 'signe_le' => $devis->signe_le->toIso8601String()]]);
    }

    /** Le propriétaire refuse le devis dans son espace, avec un mot pour la clinique s'il le veut (31.10.2026). */
    public function refuserDevis(Request $request, ContexteProprietaire $contexte, int $devisId): JsonResponse
    {
        $donnees = $request->validate(['motif' => ['nullable', 'string', 'max:500']]);
        $devis = $this->devisEnAttente($contexte, $devisId);
        $client = $devis->patient->client;
        $nom = trim(($client->prenom ?? '').' '.($client->nom ?? '')) ?: $contexte->user()->name;

        $this->repondre($devis, [
            'statut' => StatutDevis::Refuse,
            'refuse_le' => now(),
            'refuse_par' => 'Refusé par '.$nom.' dans son espace client',
            'motif_refus' => trim((string) ($donnees['motif'] ?? '')) ?: null,
        ]);
        // Reverb en panne : la réponse du client reste enregistrée, l'écran de la clinique relira au retour du WebSocket
        TempsReel::diffuser(fn () => DevisMisAJour::dispatch($devis));

        return response()->json(['data' => ['id' => $devis->id, 'statut' => $devis->statut->value, 'refuse_le' => $devis->refuse_le->toIso8601String()]]);
    }

    /**
     * La réponse ne s'écrit que sur un devis encore envoyé : si l'équipe a répondu entre-temps
     * (accord au téléphone, refus), la sienne ne l'écrase pas.
     *
     * @param  array<string, mixed>  $valeurs
     */
    private function repondre(Devis $devis, array $valeurs): void
    {
        $ecrits = Devis::withoutGlobalScopes()->whereKey($devis->id)->where('statut', StatutDevis::Envoye)->update($valeurs);
        if ($ecrits !== 1) {
            throw ValidationException::withMessages(['statut' => 'Ce devis n’est plus en attente de signature.']);
        }
        $devis->refresh();
    }

    /**
     * Seulement un devis de ses propres fiches client : celui qui gère un
     * animal réclamé par puce ne répond pas au nom du client de la fiche.
     * Encore envoyé et valable.
     */
    private function devisEnAttente(ContexteProprietaire $contexte, int $devisId): Devis
    {
        $devis = Devis::withoutGlobalScopes()
            ->with('patient.client')
            ->whereIn('patient_id', $contexte->patientIds())
            ->whereHas('patient', fn ($patient) => $patient->withoutGlobalScopes()->whereIn('client_id', $contexte->clientIds()))
            ->where('canal', CanalDevis::Portail)
            ->findOrFail($devisId);

        if ($devis->estExpire()) {
            throw ValidationException::withMessages(['statut' => 'Ce devis est expiré : demandez-en un nouveau à la clinique.']);
        }

        if ($devis->statut !== StatutDevis::Envoye) {
            throw ValidationException::withMessages(['statut' => 'Ce devis n’est plus en attente de signature.']);
        }

        return $devis;
    }

    public function demanderRenouvellement(Request $request, ContexteProprietaire $contexte, int $ordonnanceId): JsonResponse
    {
        $ordonnance = Ordonnance::withoutGlobalScopes()
            ->with('demandesRenouvellement')
            ->whereIn('patient_id', $contexte->patientIds())
            ->findOrFail($ordonnanceId);

        if ($ordonnance->statut !== StatutOrdonnance::Active) {
            throw ValidationException::withMessages(['statut' => 'Cette ordonnance n’est plus en cours.']);
        }

        if ($ordonnance->renouvellements < 1) {
            throw ValidationException::withMessages(['renouvellements' => 'Plus aucun renouvellement prévu : demandez une consultation.']);
        }

        if ($ordonnance->demandesRenouvellement->whereNull('traitee_le')->isNotEmpty()) {
            throw ValidationException::withMessages(['statut' => 'Une demande de renouvellement est déjà en attente.']);
        }

        $demande = $ordonnance->demandesRenouvellement()->create(['user_id' => $request->user()->id]);
        // La clinique le voit tout de suite (Reverb), sans recharger
        TempsReel::diffuser(fn () => RenouvellementOrdonnanceDemande::dispatch($ordonnance));

        return response()->json(['data' => ['demande_id' => $demande->id, 'ordonnance_id' => $ordonnance->id]], 201);
    }
}
