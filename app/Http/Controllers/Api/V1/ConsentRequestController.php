<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\InterventionResource;
use App\Mail\ConsentRequested;
use App\Models\ConsentRequest;
use App\Models\Intervention;
use App\Notifications\ConsentRequestSms;
use App\Services\Messages\QuotaSms;
use App\Support\Telephone;
use App\Support\TexteSms;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use NotificationChannels\Twilio\TwilioChannel;
use Throwable;

/**
 * Envoyer le consentement pour signature (demande de Jim, 02.11.2026) : un
 * lien personnel, valable 24 heures, par SMS, par e-mail, ou un avis dans
 * l'espace Veterinary du propriétaire. Un nouvel envoi annule le précédent.
 * Le propriétaire signe sur son téléphone ; la signature revient seule.
 */
class ConsentRequestController extends Controller
{
    public function __invoke(Request $request, Intervention $intervention, ContexteClinique $contexte, QuotaSms $quota): InterventionResource
    {
        $this->authorize('update', $intervention);
        $data = $request->validate([
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['string', Rule::in(['sms', 'email', 'space'])],
        ]);
        $channels = array_values(array_unique($data['channels']));

        if ($intervention->consentement_signe_le !== null) {
            throw ValidationException::withMessages(['consentement' => 'Le consentement est déjà recueilli.']);
        }

        $client = $intervention->patient->client;
        $mobile = Telephone::mobile($client->tel);
        $errors = [];
        if (in_array('sms', $channels, true) && (! QuotaSms::estConfigure() || $mobile === null)) {
            $errors['channels'] = QuotaSms::estConfigure() ? 'Pas de numéro de mobile pour ce propriétaire.' : 'Les SMS ne sont pas encore disponibles.';
        }
        if (in_array('email', $channels, true) && blank($client->email)) {
            $errors['channels'] = 'Pas d’adresse e-mail pour ce propriétaire.';
        }
        if (in_array('space', $channels, true) && $client->user_id === null) {
            $errors['channels'] = 'Ce propriétaire n’a pas encore d’espace Veterinary.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $clinic = $contexte->clinique();
        $token = ConsentRequest::newToken();

        $consentRequest = DB::transaction(function () use ($intervention, $channels, $mobile, $client, $token, $contexte): ConsentRequest {
            ConsentRequest::query()->where('intervention_id', $intervention->id)
                ->whereNull('signed_at')->whereNull('cancelled_at')
                ->update(['cancelled_at' => now()]);

            return ConsentRequest::create([
                'intervention_id' => $intervention->id,
                'token_hash' => ConsentRequest::hashOf($token),
                'channels' => $channels,
                'phone' => in_array('sms', $channels, true) ? $mobile : null,
                'email' => in_array('email', $channels, true) ? $client->email : null,
                'expires_at' => now()->addHours(ConsentRequest::VALID_HOURS),
                'sent_by_employee_id' => $contexte->employe()?->id,
            ]);
        });

        $link = $consentRequest->link($token);

        if (in_array('email', $channels, true)) {
            Mail::to($client->email)->send(new ConsentRequested($intervention->load('patient'), $clinic, $link));
        }

        if (in_array('sms', $channels, true)) {
            $text = TexteSms::versGsm($clinic->nom.' : consentement pour l’intervention de '.$intervention->patient->nom.'. Lisez et signez ici (valable 24 h) : '.$link);
            $segments = TexteSms::segments($text);
            $organisation = $clinic->societe->organisation;
            $month = $quota->reserver($organisation, $segments);
            if ($month === null) {
                throw ValidationException::withMessages(['channels' => 'Quota de SMS du mois atteint : achetez un pack dans l’abonnement.']);
            }
            try {
                Notification::route(TwilioChannel::class, $mobile)->notifyNow(new ConsentRequestSms($text));
            } catch (Throwable $e) {
                report($e);
                $quota->rendre($organisation, $month, $segments);
                throw ValidationException::withMessages(['channels' => 'Le SMS n’est pas parti : réessayez, ou envoyez par e-mail.']);
            }
        }

        return new InterventionResource($intervention->fresh()->load(InterventionController::relations()));
    }
}
