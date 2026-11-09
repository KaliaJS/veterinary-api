<?php

namespace App\Services\Patients;

use App\Auth\ContexteClinique;
use App\Mail\RecordShareLink;
use App\Models\Client;
use App\Models\Patient;
use App\Models\RecordShare;
use App\Services\Hospitalisation\OwnerMessages;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Créer un lien de partage du dossier (09.11.2026) : depuis « Partager le
 * dossier » comme depuis une lettre de référence (envoi B). Seulement des
 * pièces de cet animal, partageables aujourd'hui ; le plafond des ouvertures
 * (8 lectures complètes, au moins 300) ; l'e-mail au confrère (son échec est
 * dit) ; le propriétaire prévenu dans ses messages.
 */
final class RecordShares
{
    public function __construct(private RecordShareItems $items, private OwnerMessages $messages) {}

    /**
     * @param  list<array{type: string, id: int|string}>  $items
     * @return array{share: RecordShare, url: string, is_email_sent: ?bool}
     */
    public function create(Patient $patient, array $items, int $days, ?string $email, bool $visitNotes = false, ?string $from = null, ?string $to = null, ?string $authorName = null): array
    {
        $allowed = collect($this->items->candidates($patient, null, null))->flatMap(fn (array $g) => array_map(fn (array $i) => $i['type'].':'.$i['id'], $g['items']))->flip();
        /** @var Collection<int, array{type: string, id: int}> $chosen */
        $chosen = collect($items)->map(fn (array $i) => ['type' => (string) $i['type'], 'id' => (int) $i['id']])->unique(fn (array $i) => $i['type'].':'.$i['id'])->values();
        if ($chosen->isEmpty() || $chosen->contains(fn (array $i) => ! $allowed->has($i['type'].':'.$i['id']))) {
            throw ValidationException::withMessages(['items' => 'Une pièce n’est pas de ce dossier, ou ne peut pas être partagée.']);
        }

        // Le plafond des ouvertures : 8 lectures complètes (pièces et fichiers), au moins 300
        $files = $chosen->sum(fn (array $i) => $this->items->fileCount($patient, $i['type'], $i['id']));
        $openingsMax = max(RecordShare::MIN_OPENINGS, RecordShare::OPENINGS_READINGS * ($chosen->count() + $files));

        $token = Str::random(48);
        $employe = app(ContexteClinique::class)->employe();
        $share = RecordShare::query()->create([
            'organisation_id' => $patient->organisation_id,
            'patient_id' => $patient->id,
            'kind' => 'link',
            'token_hash' => RecordShare::hash($token),
            'recipient_email' => $email,
            'items' => $chosen->all(),
            'include_visit_notes' => $visitNotes,
            'period_from' => $from,
            'period_to' => $to,
            'expires_at' => now()->addDays($days),
            'openings_max' => $openingsMax,
            'created_by_id' => $employe?->id,
            'created_by_name' => $employe->nom ?? $authorName,
        ]);
        $url = self::url($token);
        $clinique = app(ContexteClinique::class)->clinique();

        // L'e-mail qui n'a pas pu partir est dit à l'écran : le lien reste, à copier
        $isEmailSent = null;
        if ($email !== null && $clinique !== null) {
            try {
                Mail::to($email)->send(new RecordShareLink($share, $clinique, $patient, $url));
                $isEmailSent = true;
            } catch (Throwable $e) {
                report($e);
                $isEmailSent = false;
            }
        }
        $this->tellOwner($patient, $share);

        return ['share' => $share, 'url' => $url, 'is_email_sent' => $isEmailSent];
    }

    public static function url(string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/share/'.$token;
    }

    /** Le propriétaire qui a un compte apprend, dans ses messages, avec qui le dossier est partagé */
    private function tellOwner(Patient $patient, RecordShare $share): void
    {
        $client = $patient->client_id ? Client::query()->find($patient->client_id) : null;
        if ($client?->user_id === null) {
            return;
        }
        $text = 'Le dossier de '.$patient->nom.' a été partagé par '.($share->created_by_name ?: 'la clinique')
            .($share->recipient_email ? ' avec '.$share->recipient_email : ' par un lien provisoire')
            .', jusqu’au '.$share->expiryDay().'.';
        [$message, $first] = DB::transaction(fn () => $this->messages->createFor((int) $share->clinique_id, $client, $text));
        $this->messages->notifyFor((int) $share->clinique_id, $client, $message, $text, $first);
    }
}
