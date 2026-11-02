<?php

namespace App\Services\Bloc;

use App\Enums\ConsentMethod;
use App\Enums\PushType;
use App\Events\InterventionUpdated;
use App\Models\ConsentRequest;
use App\Models\Employe;
use App\Models\Intervention;
use App\Services\Notifications\Push;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Le consentement éclairé d'une intervention, de quelque façon qu'il
 * soit recueilli (02.11.2026) : signé à la clinique, signé à distance par
 * le lien reçu, accord oral ou urgence. Il coche le point `consent` de la
 * checklist, seule voie pour le cocher, et ferme les envois en attente.
 * Une signature archive un document au dossier du patient (l'image sur
 * le disque privé, le nom de qui a signé).
 */
class ConsentRecorder
{
    public function sign(Intervention $intervention, string $signer, string $png, ConsentMethod $method, ?Employe $collector = null, ?ConsentRequest $request = null, ?string $ip = null, ?string $userAgent = null): void
    {
        $this->assertNotYet($intervention);

        $path = 'consentements/'.$intervention->id.'-'.Str::uuid().'.png';
        Storage::disk('prive')->put($path, $png);

        try {
            DB::transaction(function () use ($intervention, $signer, $png, $method, $collector, $request, $ip, $userAgent, $path): void {
                // Relue sous verrou : deux signatures en même temps n'en gardent qu'une
                $fresh = Intervention::withoutGlobalScopes()->lockForUpdate()->findOrFail($intervention->id);
                $this->assertNotYet($fresh);
                // Le lien relu sous le même verrou : remplacé ou expiré entre-temps, il ne signe plus rien
                if ($request !== null) {
                    $request = ConsentRequest::withoutGlobalScopes()->lockForUpdate()->findOrFail($request->id);
                    abort_unless($request->isOpen(), 410, 'Ce lien a été remplacé par un plus récent, ou n’est plus valable.');
                }

                $document = $intervention->patient->documents()->create([
                    'nom' => 'Consentement éclairé : '.$intervention->procedure,
                    'type' => 'consentement',
                    'chemin' => $path,
                    'taille_octets' => strlen($png),
                    'signataire' => trim($signer),
                    'etabli_le' => now($intervention->clinique?->fuseau_horaire ?: 'Europe/Zurich')->toDateString(),
                ]);

                $fresh->update([
                    'consentement_signe_le' => now(),
                    'consentement_document_id' => $document->id,
                    'consentement_recueilli_par_id' => $collector?->id,
                    'consent_method' => $method,
                    'consent_details' => null,
                ]);

                $request?->forceFill(['signed_at' => now(), 'signed_ip' => $ip, 'signed_user_agent' => $userAgent ? mb_substr($userAgent, 0, 500) : null])->save();
                $this->close($fresh);
            });
        } catch (Throwable $e) {
            Storage::disk('prive')->delete($path);

            throw $e;
        }

        $intervention->refresh();
        InterventionUpdated::dispatch($intervention);

        // Signé à distance : le chirurgien et qui a envoyé le lien le savent sur leur téléphone (02.11.2026)
        if ($method === ConsentMethod::Remote) {
            $employes = Employe::withoutGlobalScopes()->whereIn('id', array_filter([$intervention->chirurgien_id, $request?->sent_by_employee_id]))->pluck('user_id');
            Push::to($employes, PushType::ConsentSigned, 'Consentement signé', trim($signer).' a signé pour '.$intervention->patient->nom.' : '.$intervention->procedure, '/clinique/bloc');
        }
    }

    /**
     * Sans signature : accord oral (qui, comment, ce qui a été expliqué) ou
     * urgence. Le vétérinaire connecté en prend la responsabilité.
     *
     * @param  array{given_by?: string, means?: string, explanation?: string}  $details
     */
    public function withoutSignature(Intervention $intervention, ConsentMethod $method, array $details, Employe $veterinarian): void
    {
        DB::transaction(function () use ($intervention, $method, $details, $veterinarian): void {
            $fresh = Intervention::withoutGlobalScopes()->lockForUpdate()->findOrFail($intervention->id);
            $this->assertNotYet($fresh);

            $fresh->update([
                'consentement_signe_le' => now(),
                'consentement_document_id' => null,
                'consentement_recueilli_par_id' => $veterinarian->id,
                'consent_method' => $method,
                'consent_details' => $method === ConsentMethod::Oral ? [
                    'given_by' => trim((string) ($details['given_by'] ?? '')),
                    'means' => $details['means'] ?? null,
                    'explanation' => trim((string) ($details['explanation'] ?? '')),
                ] : null,
            ]);

            $this->close($fresh);
        });

        $intervention->refresh();
        InterventionUpdated::dispatch($intervention);
    }

    private function assertNotYet(Intervention $intervention): void
    {
        if ($intervention->consentement_signe_le !== null) {
            $signed = in_array($intervention->consent_method, [null, ConsentMethod::Signed, ConsentMethod::Remote], true);

            throw ValidationException::withMessages(['consentement' => $signed ? 'Le consentement est déjà signé.' : 'Le consentement est déjà recueilli.']);
        }
    }

    /** Point `consent` coché, envois encore ouverts annulés */
    private function close(Intervention $intervention): void
    {
        $intervention->checklist()->where('code', 'consent')->update(['est_coche' => true]);
        ConsentRequest::withoutGlobalScopes()
            ->where('intervention_id', $intervention->id)
            ->whereNull('signed_at')
            ->whereNull('cancelled_at')
            ->update(['cancelled_at' => now()]);
    }
}
