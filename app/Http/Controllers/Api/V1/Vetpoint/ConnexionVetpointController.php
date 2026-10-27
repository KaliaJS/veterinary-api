<?php

namespace App\Http\Controllers\Api\V1\Vetpoint;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Models\ConnexionVetpoint;
use App\Services\Vetpoint\ClientVetpoint;
use App\Services\Vetpoint\VetpointCgv;
use App\Services\Vetpoint\VetpointCleRefusee;
use App\Services\Vetpoint\VetpointIndisponible;
use App\Services\Vetpoint\VetpointLimite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Clé Vetpoint de la clinique du jeton (Réglages, Fournisseurs, 27.10.2026) :
 * vérifiée auprès de Vetpoint avant d'être gardée, chiffrée, jamais
 * renvoyée (seuls ses 4 derniers caractères). Droit « Organisation ».
 */
class ConnexionVetpointController extends Controller
{
    public function __construct(private ContexteClinique $contexte) {}

    public function show(): JsonResponse
    {
        return $this->etat();
    }

    public function update(Request $request): JsonResponse
    {
        $request->merge(['cle' => trim((string) $request->input('cle'))]);
        $cle = $request->validate([
            // Caractères visibles seulement : un caractère invisible ne peut
            // pas partir dans un en-tête HTTP
            'cle' => ['required', 'string', 'min:10', 'max:200', 'regex:/^[\x21-\x7E]+$/'],
        ], [
            'cle.regex' => 'Cette clé contient des caractères inattendus : copiez-la à nouveau depuis votre profil Vetpoint.',
            'cle.required' => 'Collez la clé générée dans votre profil Vetpoint.',
            'cle.min' => 'Cette clé est trop courte : copiez-la en entier depuis votre profil Vetpoint.',
        ])['cle'];

        try {
            $fournisseurs = ClientVetpoint::pour($cle)->fournisseurs();
        } catch (VetpointCleRefusee) {
            throw ValidationException::withMessages(['cle' => 'Vetpoint refuse cette clé : vérifiez-la ou générez-en une nouvelle dans votre profil Vetpoint.']);
        } catch (VetpointCgv) {
            throw ValidationException::withMessages(['cle' => 'Vetpoint refuse l’accès : cochez « J’accepte les CGV » dans votre profil Vetpoint, puis réessayez.']);
        } catch (VetpointLimite) {
            abort(429, 'Vetpoint limite les appels : réessayez dans une minute.');
        } catch (VetpointIndisponible) {
            abort(503, 'Vetpoint ne répond pas : réessayez dans un moment.');
        }

        ConnexionVetpoint::query()->updateOrCreate(
            ['clinique_id' => $this->cliniqueId()],
            ['cle' => $cle, 'verifiee_le' => now()],
        );
        // Lus pour vérifier la clé : gardés pour la recherche (un appel de moins)
        Cache::put(self::cleCacheFournisseurs($this->cliniqueId()), $fournisseurs, now()->addDay());

        return $this->etat();
    }

    public function destroy(): Response
    {
        ConnexionVetpoint::query()->where('clinique_id', $this->cliniqueId())->delete();
        Cache::forget(self::cleCacheFournisseurs($this->cliniqueId()));

        return response()->noContent();
    }

    /** Les noms des fournisseurs d'une clinique, gardés un jour (recherche). */
    public static function cleCacheFournisseurs(int $cliniqueId): string
    {
        return 'vetpoint:fournisseurs:'.$cliniqueId;
    }

    private function cliniqueId(): int
    {
        return (int) $this->contexte->cliniqueId();
    }

    private function etat(): JsonResponse
    {
        $connexion = ConnexionVetpoint::query()->where('clinique_id', $this->cliniqueId())->first();

        return response()->json(['data' => [
            'connectee' => $connexion !== null,
            'cle_fin' => $connexion ? mb_substr($connexion->cle, -4) : null,
            'verifiee_le' => $connexion?->verifiee_le?->toIso8601String(),
            'profil_url' => (string) config('services.vetpoint.profil'),
        ]]);
    }
}
