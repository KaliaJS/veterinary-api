<?php

namespace App\Http\Controllers\Dev;

use App\Enums\IsabvForm;
use App\Http\Controllers\Controller;
use App\Models\IsabvCode;
use App\Models\IsabvPreparation;
use App\Services\Isabv\DeclarationXml;
use DOMDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Faux serveur SI ABV de l'OSAV, en local et dans les essais seulement (08.11.2026) :
 * les essais locaux n'envoient jamais rien au vrai serveur. Il répond comme
 * le Swagger de l'OSAV (3.0.0) et valide chaque prescription contre le XSD
 * officiel de son formulaire. Un token qui commence par « refuse » est
 * refusé ; une clé de diagnostic « 99.99 » rend un NOK, pour essayer la
 * correction.
 */
class FakeIsabvController extends Controller
{
    private const STORE = 'fake-isabv:';

    public function createJwt(Request $request): Response
    {
        $uid = $request->json('uid');
        $token = $request->json('token');
        if (! is_string($uid) || ! is_string($token) || $uid === '' || $token === '') {
            return response('', 400);
        }
        if (str_starts_with($token, 'refuse')) {
            return response('', 401);
        }

        return response('fake.'.rtrim(strtr(base64_encode((string) json_encode(['uid' => $uid, 'appendix' => $request->json('appendix'), 'exp' => time() + 10])), '+/', '-_'), '=').'.signature', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function createPrescription(Request $request, string $type): JsonResponse|Response
    {
        if (($office = $this->office($request)) === null) {
            return response('', 401);
        }
        $form = IsabvForm::tryFrom($type);
        if ($form === null) {
            return $this->nok($office, null, $type, 'Type de prescription inconnu.');
        }
        $xml = $request->getContent();
        $errors = DeclarationXml::schemaErrors($xml, $form);
        $document = new DOMDocument;
        @$document->loadXML($xml, LIBXML_NONET);
        $read = fn (string $name) => $document->getElementsByTagName($name)->item(0)?->textContent;
        $recipe = $read('recipeNumber');
        if ($errors !== []) {
            return $this->nok($office, $recipe, $type, 'Structure non valide : '.implode(' ; ', $errors));
        }
        if ($read('veterinaryPracticeUID') !== $office['uid']) {
            return $this->nok($office, $recipe, $type, 'Le numéro IDE ne correspond pas au cabinet du token.');
        }
        if (Cache::has(self::STORE.$recipe)) {
            return $this->nok($office, $recipe, $type, 'Ce numéro de prescription existe déjà.');
        }
        $codes = [];
        foreach ($document->getElementsByTagName('preparationID') as $element) {
            $codes[] = $element->textContent;
        }
        if (! IsabvPreparation::query()->whereIn('code', $codes)->where('must_transfer', true)->exists()) {
            return response('', 409);
        }
        if ($read('diagnosisKey') === '99.99') {
            return $this->nok($office, $recipe, $type, 'Clé de diagnostic : la valeur manque ou n’existe pas.');
        }
        $protocol = $this->protocol($office, $recipe, $type, 'OK');
        Cache::put(self::STORE.$recipe, ['protocol' => $protocol, 'active' => true], now()->addYear());
        $this->remember($office, $protocol);

        return response()->json(['uuid' => (string) Str::uuid(), 'duplicate' => false, 'protocol' => $protocol], 201);
    }

    public function deactivate(Request $request, string $prescriptionId): JsonResponse|Response
    {
        if (($office = $this->office($request)) === null) {
            return response('', 401);
        }
        $stored = Cache::get(self::STORE.$prescriptionId);
        if ($stored === null) {
            return response('', 400);
        }
        Cache::put(self::STORE.$prescriptionId, [...$stored, 'active' => false], now()->addYear());

        return response()->json($this->protocol($office, $prescriptionId, $stored['protocol']['type'] ?? null, 'OK'));
    }

    public function protocolSearch(Request $request): JsonResponse|Response
    {
        if (($office = $this->office($request)) === null) {
            return response('', 401);
        }
        $id = $request->json('prescriptionID');
        $entries = array_values(array_filter(Cache::get(self::STORE.'protocol:'.$office['uid'], []), fn (array $entry) => ! is_string($id) || $entry['prescriptionId'] === $id));

        return response()->json($entries);
    }

    public function saveConsultation(Request $request): JsonResponse|Response
    {
        if (($office = $this->office($request)) === null) {
            return response('', 401);
        }
        $year = $request->json('year');
        $species = $request->json('animalType');
        $isValid = is_int($year) && in_array($year, [(int) now()->year - 1, (int) now()->year], true) && is_int($request->json('consultationPerYear'))
            && IsabvCode::query()->ofKind(IsabvCode::KIND_SPECIES)->where('code', $species)->where('is_valid_for_consultations', true)->exists();
        if (! $isValid) {
            return response('', 400);
        }
        $key = self::STORE.'consultation:'.$office['uid'].':'.$year.':'.$species;
        $replaced = Cache::has($key);
        Cache::put($key, $request->json('consultationPerYear'), now()->addYear());

        return response()->json([...$this->protocol($office, null, null, 'OK'), 'consultationID' => (string) Str::uuid()], $replaced ? 200 : 201);
    }

    public function duplicates(Request $request): JsonResponse|Response
    {
        if (($office = $this->office($request)) === null) {
            return response('', 401);
        }

        return response()->json([...$this->protocol($office, null, null, 'OK'), 'prescriptionIDs' => Cache::get(self::STORE.'duplicates:'.$office['uid'], [])]);
    }

    public function reactivate(Request $request): JsonResponse|Response
    {
        if (($office = $this->office($request)) === null) {
            return response('', 401);
        }
        $ids = $request->json('prescriptionIDs');
        Cache::put(self::STORE.'duplicates:'.$office['uid'], array_values(array_diff(Cache::get(self::STORE.'duplicates:'.$office['uid'], []), is_array($ids) ? $ids : [])), now()->addYear());

        return response()->json($this->protocol($office, null, null, 'OK'));
    }

    /** @return array{uid: string, appendix: ?string}|null */
    private function office(Request $request): ?array
    {
        $jwt = (string) $request->bearerToken();
        if (! str_starts_with($jwt, 'fake.')) {
            return null;
        }
        $payload = json_decode((string) base64_decode(strtr(explode('.', $jwt)[1] ?? '', '-_', '+/')), true);

        return is_array($payload) && is_string($payload['uid'] ?? null) ? ['uid' => $payload['uid'], 'appendix' => $payload['appendix'] ?? null] : null;
    }

    /**
     * @param  array{uid: string, appendix: ?string}  $office
     * @return array<string, mixed>
     */
    private function protocol(array $office, ?string $recipe, ?string $type, string $status, ?string $message = null): array
    {
        return array_filter([
            'uuid' => (string) Str::uuid(),
            'uid' => $office['uid'],
            'appendix' => $office['appendix'],
            'createdOn' => now()->format('Y-m-d\TH:i:s'),
            'prescriptionId' => $recipe,
            'type' => $type,
            'status' => $status,
            'message' => $message !== null ? ['de' => $message, 'fr' => $message, 'it' => $message] : null,
        ], fn ($value) => $value !== null);
    }

    /** @param array{uid: string, appendix: ?string} $office */
    private function nok(array $office, ?string $recipe, string $type, string $message): JsonResponse
    {
        $protocol = $this->protocol($office, $recipe, $type, 'NOK', $message);
        $this->remember($office, $protocol);

        return response()->json($protocol, 400);
    }

    /**
     * @param  array{uid: string, appendix: ?string}  $office
     * @param  array<string, mixed>  $protocol
     */
    private function remember(array $office, array $protocol): void
    {
        $key = self::STORE.'protocol:'.$office['uid'];
        Cache::put($key, [...array_slice(Cache::get($key, []), -500), $protocol], now()->addYear());
    }
}
