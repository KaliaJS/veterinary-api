<?php

namespace App\Http\Controllers\Api\V1\Isabv;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Jobs\SendAntibioticDeclaration;
use App\Models\AntibioticDeclaration;
use App\Models\Clinique;
use App\Models\IsabvConnection;
use App\Services\Isabv\DeclarationXml;
use App\Services\Isabv\IsabvClient;
use App\Services\Isabv\IsabvTokenRefused;
use App\Services\Isabv\IsabvUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Réglages, SI ABV (08.11.2026) : comment la clinique déclare ses
 * antibiotiques. Variante 1 : par Veterinary, avec le token d'interface que
 * l'OSAV envoie par e-mail à l'inscription (IDE et complément), vérifié par
 * l'OSAV avant d'être gardé (chiffré, jamais renvoyé : ses 4 derniers
 * caractères) ; variante 2 : rien n'est envoyé. Le complément ne contient ni
 * $, ni & ni % (directives, ch. 12.3.2). Droit « Organisation ».
 */
class IsabvConnectionController extends Controller
{
    public function __construct(private ContexteClinique $contexte) {}

    /** Qui prescrit sait si la clinique déclare par Veterinary ; le reste du réglage, au droit Organisation */
    public function show(): JsonResponse
    {
        if (! Gate::allows('organisation')) {
            $connection = IsabvConnection::query()->where('clinique_id', $this->cliniqueId())->first();

            return response()->json(['data' => ['variant' => $connection?->variant, 'is_sending' => $connection?->sendsThroughVeterinary() ?? false]]);
        }

        return $this->state();
    }

    public function update(Request $request): JsonResponse
    {
        $request->merge(['token' => is_string($request->input('token')) ? trim($request->input('token')) : $request->input('token'),
            'appendix' => is_string($request->input('appendix')) ? trim($request->input('appendix')) : $request->input('appendix')]);
        $data = $request->validate([
            'variant' => ['required', 'integer', Rule::in([IsabvConnection::VIA_VETERINARY, IsabvConnection::VIA_OSAV_WEB])],
            'appendix' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[^$&%]*$/'],
            'token' => ['sometimes', 'nullable', 'string', 'min:8', 'max:200', 'regex:/^[\x21-\x7E]+$/'],
        ], [
            'appendix.regex' => 'Le complément ne contient ni $, ni & ni % (règle de l’OSAV).',
            'token.regex' => 'Ce token contient des caractères inattendus : copiez-le à nouveau depuis l’e-mail de l’OSAV.',
            'token.min' => 'Ce token est trop court : copiez-le en entier depuis l’e-mail de l’OSAV.',
        ]);
        $connection = IsabvConnection::query()->firstOrNew(['clinique_id' => $this->cliniqueId()]);
        $appendix = array_key_exists('appendix', $data) ? ($data['appendix'] ?: null) : $connection->appendix;
        $token = ($data['token'] ?? null) ?: $connection->plainToken();

        if ((int) $data['variant'] === IsabvConnection::VIA_VETERINARY) {
            $uid = DeclarationXml::uid($this->clinique());
            if ($uid === null) {
                throw ValidationException::withMessages(['variant' => 'Le numéro IDE de la société manque : il identifie la clinique à l’OSAV.']);
            }
            if ($token === null) {
                throw ValidationException::withMessages(['token' => 'Collez le token de l’interface du logiciel du cabinet, reçu de l’OSAV par e-mail.']);
            }
            $this->refuseTwin($appendix);
            try {
                IsabvClient::for($uid, $appendix, $token)->jwt();
            } catch (IsabvTokenRefused) {
                throw ValidationException::withMessages(['token' => 'L’OSAV refuse ce token pour cet IDE et ce complément. Vérifiez qu’il s’agit du token de l’interface du logiciel du cabinet (pas celui de l’application web), et le complément.']);
            } catch (IsabvUnavailable) {
                abort(503, 'Le serveur de l’OSAV ne répond pas : réessayez dans un moment.');
            }
            $connection->forceFill(['token' => Crypt::encryptString($token), 'verified_at' => now()]);
        } elseif (($data['token'] ?? null) !== null) {
            // Gardé pour un retour à la variante 1, sans être vérifié
            $connection->forceFill(['token' => Crypt::encryptString($data['token']), 'verified_at' => null]);
        }
        DB::transaction(function () use ($connection, $data, $appendix): void {
            if ((int) $data['variant'] === IsabvConnection::VIA_VETERINARY) {
                // Revu sous le verrou de la société : deux enregistrements au même instant ne passent pas tous deux
                DB::table('societes')->where('id', $this->clinique()->societe_id)->lockForUpdate()->first();
                $this->refuseTwin($appendix);
            }
            $connection->fill(['variant' => (int) $data['variant'], 'appendix' => $appendix])->save();
        });

        if ($connection->sendsThroughVeterinary()) {
            // Ce qui attendait un token accepté repart
            AntibioticDeclaration::query()->whereIn('status', ['ready', 'replaced'])->pluck('id')
                ->each(fn (int $id) => SendAntibioticDeclaration::dispatch($id));
        }

        return $this->state();
    }

    /**
     * Un IDE et un complément désignent une seule clinique à l'OSAV (directives,
     * ch. 3) : deux sites s'inscrivent chacun avec son complément, sinon l'un
     * écraserait l'autre (le nombre de consultations, par exemple).
     */
    private function refuseTwin(?string $appendix): void
    {
        $clinique = $this->clinique();
        $twin = IsabvConnection::withoutGlobalScopes()->where('variant', IsabvConnection::VIA_VETERINARY)->where('clinique_id', '!=', $clinique->id)
            ->whereIn('clinique_id', Clinique::withoutGlobalScopes()->where('societe_id', $clinique->societe_id)->select('id'))
            ->get()->contains(fn (IsabvConnection $other) => mb_strtolower(trim((string) $other->appendix)) === mb_strtolower(trim((string) $appendix)));
        if ($twin) {
            throw ValidationException::withMessages(['appendix' => 'Une autre clinique de la société est déjà reliée avec cet IDE et ce complément : chaque site s’inscrit à l’OSAV avec son propre complément.']);
        }
    }

    private function state(): JsonResponse
    {
        $connection = IsabvConnection::query()->where('clinique_id', $this->cliniqueId())->first();
        $token = $connection?->plainToken();

        return response()->json(['data' => [
            'variant' => $connection?->variant,
            'appendix' => $connection?->appendix,
            'uid' => DeclarationXml::uid($this->clinique()),
            'has_token' => $token !== null,
            'token_end' => $token !== null ? mb_substr($token, -4) : null,
            'verified_at' => $connection?->verified_at?->toIso8601String(),
            'is_sending' => $connection?->sendsThroughVeterinary() ?? false,
            'web_url' => (string) config('services.isabv.web_url'),
            // Pas encore parties ni désactivées : à transmettre avant un passage en variante 2 (manuel de l'OSAV, ch. 7.3.1)
            'pending_count' => AntibioticDeclaration::query()->whereIn('status', ['to_complete', 'rejected', 'ready', 'replaced'])->count(),
        ]]);
    }

    private function cliniqueId(): int
    {
        return (int) $this->contexte->cliniqueId();
    }

    private function clinique(): Clinique
    {
        return Clinique::query()->with('societe')->findOrFail($this->cliniqueId());
    }
}
