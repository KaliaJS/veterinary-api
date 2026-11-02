<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\CalculateurPermissions;
use App\Enums\DomainePermission;
use App\Enums\PushType;
use App\Http\Controllers\Controller;
use App\Models\AppareilNotification;
use App\Models\Client;
use App\Models\Employe;
use App\Models\NotificationSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Les réglages des notifications du téléphone de chacun (demande de Jim,
 * 02.11.2026) : les sortes qui le concernent (propriétaire, équipe, ou les
 * deux), la nuit (oui ou non, et ses heures), les urgences même la nuit.
 */
class NotificationSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present(NotificationSetting::for($request->user()->id), $request->user()->id)]);
    }

    public function update(Request $request): JsonResponse
    {
        $hhmm = 'regex:/^([01]\d|2[0-3]):[0-5]\d$/';
        $data = $request->validate([
            'disabled_types' => ['present', 'array'],
            'disabled_types.*' => ['string', Rule::enum(PushType::class)],
            'quiet_enabled' => ['required', 'boolean'],
            'quiet_from' => ['required', 'string', $hhmm],
            'quiet_to' => ['required', 'string', $hhmm],
            'urgent_at_night' => ['required', 'boolean'],
            'timezone' => ['required', 'string', 'timezone:all'],
        ], [
            'quiet_from.regex' => 'L’heure de début de la nuit doit s’écrire 22:00.',
            'quiet_to.regex' => 'L’heure de fin de la nuit doit s’écrire 07:00.',
        ]);
        $settings = NotificationSetting::for($request->user()->id);
        $settings->fill([...$data, 'disabled_types' => array_values(array_unique($data['disabled_types']))])->save();

        return response()->json(['data' => $this->present($settings, $request->user()->id)]);
    }

    /** Ce téléphone ne doit plus rien recevoir pour ce compte (déconnexion) */
    public function forgetDevice(Request $request): Response
    {
        $data = $request->validate(['jeton' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64,200}$/']]);
        AppareilNotification::query()->where('user_id', $request->user()->id)->where('jeton', strtolower($data['jeton']))->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function present(NotificationSetting $s, int $userId): array
    {
        $isOwner = Client::withoutGlobalScopes()->where('user_id', $userId)->exists();
        $employes = Employe::withoutGlobalScopes()->with(['role' => fn ($q) => $q->withoutGlobalScopes(), 'derogations' => fn ($q) => $q->withoutGlobalScopes()])->where('user_id', $userId)->where('acces_suspendu', false)->get();
        $isStaff = $employes->isNotEmpty();
        $calcul = app(CalculateurPermissions::class);
        $hasPlanning = $employes->contains(fn (Employe $e): bool => in_array(DomainePermission::Planning->value, $calcul->pour($e), true));
        $types = array_values(array_filter(PushType::cases(), fn (PushType $t): bool => match (true) {
            $t === PushType::Emergency => $hasPlanning,
            $t->forStaff() => $isStaff,
            default => $isOwner,
        }));

        return [
            'types' => array_map(fn (PushType $t): array => [
                'key' => $t->value,
                'label' => $t->label(),
                'for_staff' => $t->forStaff(),
                'enabled' => ! in_array($t->value, $s->disabled_types ?? [], true),
            ], $types),
            'quiet_enabled' => (bool) $s->quiet_enabled,
            'quiet_from' => $s->quiet_from,
            'quiet_to' => $s->quiet_to,
            'urgent_at_night' => (bool) $s->urgent_at_night,
            'timezone' => $s->timezone,
            'has_emergencies' => $hasPlanning,
        ];
    }
}
