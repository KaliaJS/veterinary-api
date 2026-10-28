<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Bloc\SignerConsentementRequest;
use App\Http\Resources\V1\InterventionResource;
use App\Models\Intervention;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Signature du consentement éclairé (bsOk du front) : archive un
 * document au dossier du patient, horodate la signature et coche le
 * point `consent`, seule voie pour le cocher. Depuis le 23.10.2026, la
 * preuve est gardée : l'image de la signature (disque privé), le nom
 * de qui a signé et l'employé qui l'a recueillie.
 */
class ConsentementInterventionController extends Controller
{
    public function __invoke(SignerConsentementRequest $request, Intervention $intervention): InterventionResource
    {
        $this->authorize('update', $intervention);

        if ($intervention->consentement_signe_le !== null) {
            throw ValidationException::withMessages(['consentement' => 'Le consentement est déjà signé.']);
        }

        $contexte = app(ContexteClinique::class);
        $chemin = 'consentements/'.$intervention->id.'-'.Str::uuid().'.png';
        Storage::disk('prive')->put($chemin, (string) $request->signaturePng());

        try {
            DB::transaction(function () use ($request, $intervention, $contexte, $chemin): void {
                $document = $intervention->patient->documents()->create([
                    'nom' => 'Consentement éclairé : '.$intervention->procedure,
                    'type' => 'consentement',
                    'chemin' => $chemin,
                    // Compte dans le stockage dès la signature (28.10.2026)
                    'taille_octets' => strlen((string) $request->signaturePng()),
                    'signataire' => trim((string) $request->validated('signataire')),
                    'etabli_le' => now($contexte->clinique()?->fuseau_horaire ?: 'Europe/Zurich')->toDateString(),
                ]);

                $intervention->update([
                    'consentement_signe_le' => now(),
                    'consentement_document_id' => $document->id,
                    'consentement_recueilli_par_id' => $contexte->employe()?->id,
                ]);

                $intervention->checklist()->where('code', 'consent')->update(['est_coche' => true]);
            });
        } catch (Throwable $e) {
            Storage::disk('prive')->delete($chemin);

            throw $e;
        }

        return new InterventionResource($intervention->load([
            'patient.client',
            'chirurgien' => fn ($requete) => $requete->withoutGlobalScope('clinique'),
            'checklist',
            'transitions',
        ]));
    }
}
