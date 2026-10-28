<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Enums\CanalDevis;
use App\Enums\StatutDevis;
use App\Enums\StatutOrdonnance;
use App\Http\Controllers\Controller;
use App\Models\Devis;
use App\Models\Document;
use App\Models\Ordonnance;
use App\Services\DocumentsProprietaire;
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

    public function signerDevis(ContexteProprietaire $contexte, int $devisId): JsonResponse
    {
        // Seulement un devis de ses propres fiches client : celui qui gère
        // un animal réclamé par puce ne signe pas au nom du client de la fiche
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

        $devis->update([
            'statut' => StatutDevis::Accepte,
            'signe_le' => now(),
            'signe_par' => 'Signature électronique de '.$contexte->user()->name.', espace client',
        ]);

        return response()->json(['data' => ['id' => $devis->id, 'statut' => $devis->statut->value, 'signe_le' => $devis->signe_le->toIso8601String()]]);
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

        return response()->json(['data' => ['demande_id' => $demande->id, 'ordonnance_id' => $ordonnance->id]], 201);
    }
}
