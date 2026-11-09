<?php

namespace App\Jobs;

use App\Enums\DeclarationStatus;
use App\Models\AntibioticDeclaration;
use App\Models\IsabvConnection;
use App\Models\StayTreatment;
use App\Services\Isabv\DeclarationRules;
use App\Services\Isabv\Declarations;
use App\Services\Isabv\DeclarationXml;
use App\Services\Isabv\IsabvClient;
use App\Services\Isabv\IsabvResult;
use App\Services\Isabv\IsabvTokenRefused;
use App\Services\Isabv\IsabvUnavailable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;

/**
 * Envoie une déclaration SI ABV prête, ou désactive à l'OSAV celle qu'une
 * correction remplace (08.11.2026). La tâche ne porte que l'identifiant.
 * Un envoi dont la réponse s'est perdue n'est jamais refait à l'aveugle :
 * le protocole de communication de l'OSAV dit d'abord s'il est arrivé
 * (directives, ch. 5.3 et 5.5). Une correction ne part qu'une fois
 * l'ancienne désactivée. Le serveur injoignable : nouvel essai plus tard
 * (la commande `isabv:send` reprend aussi ce qui attend).
 */
final class SendAntibioticDeclaration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Une seule tâche en file par déclaration : une longue panne ne la remplit pas (la relance de 15 minutes s'y heurte) */
    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return (string) $this->declarationId;
    }

    public int $tries = 8;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 1800, 3600, 7200, 14400];

    public int $timeout = 120;

    public function __construct(public int $declarationId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('isabv-declaration:'.$this->declarationId))->releaseAfter(30)->expireAfter($this->timeout + 60)];
    }

    public function handle(): void
    {
        $declaration = AntibioticDeclaration::withoutGlobalScopes()->with(['lines', 'clinique.societe', 'source'])->find($this->declarationId);
        if ($declaration === null) {
            return;
        }
        $connection = IsabvConnection::withoutGlobalScopes()->where('clinique_id', $declaration->clinique_id)->first();
        $uid = DeclarationXml::uid($declaration->clinique);
        // En variante 2, Veterinary n'envoie rien, ni envoi ni désactivation (la clinique fait tout sur le site de l'OSAV)
        if ($uid === null || $connection === null || ! $connection->sendsThroughVeterinary()) {
            return;
        }
        // Désactivée sous le complément de son envoi (« » : envoyée sans complément), même s'il a changé depuis
        $appendix = $declaration->status === DeclarationStatus::Replaced && $declaration->sent_appendix !== null ? $declaration->sent_appendix : $connection->appendix;
        $client = IsabvClient::for($uid, $appendix, (string) $connection->plainToken());
        try {
            match ($declaration->status) {
                DeclarationStatus::Replaced => $this->deactivate($declaration, $client),
                DeclarationStatus::Ready => $this->send($declaration, $client, $connection),
                // Partie, la tâche reprise après un échec du recompte : il se refait
                DeclarationStatus::Sent => $this->afterAnswer($declaration->id, $client, (string) $appendix),
                default => null,
            };
        } catch (IsabvTokenRefused) {
            if ((string) $appendix !== (string) $connection->appendix) {
                // Partie sous un autre complément, que ce token ne couvre pas : à désactiver sur le site de l'OSAV
                $declaration->forceFill(['osav_message' => ((string) $appendix === '' ? 'Envoyée sans complément, sous l’ancien complément de la clinique' : 'Envoyée sous l’ancien complément « '.$appendix.' »').' : désactivez-la dans l’application web de l’OSAV.'])->save();

                return;
            }
            // Le token ne vaut plus : la clinique le recolle dans Réglages, l'envoi reprendra
            $connection->forceFill(['verified_at' => null])->save();
        } catch (IsabvUnavailable) {
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)] ?? 3600);
        }
    }

    private function deactivate(AntibioticDeclaration $declaration, IsabvClient $client): void
    {
        $client->deactivate($declaration->recipe_number);
        $declaration->forceFill(['status' => DeclarationStatus::Deactivated, 'deactivated_at' => now(), 'is_outcome_unknown' => false])->save();
        // Sa correction, si elle attendait
        AntibioticDeclaration::withoutGlobalScopes()->where('replaces_id', $declaration->id)->where('status', DeclarationStatus::Ready)
            ->pluck('id')->each(fn (int $id) => self::dispatch($id));
    }

    private function send(AntibioticDeclaration $declaration, IsabvClient $client, IsabvConnection $connection): void
    {
        $replaced = $declaration->replaces_id !== null ? AntibioticDeclaration::withoutGlobalScopes()->find($declaration->replaces_id) : null;
        if ($replaced !== null && $replaced->status === DeclarationStatus::Replaced) {
            // L'ancienne d'abord : sa désactivation relancera celle-ci
            self::dispatch($replaced->id);

            return;
        }
        if ($declaration->source_type !== null && $declaration->source === null) {
            // L'ordonnance a été annulée entre-temps
            $declaration->delete();

            return;
        }
        if ($declaration->is_outcome_unknown) {
            $known = $client->protocolFor($declaration->recipe_number);
            if ($known !== null) {
                $this->record($declaration, $known, $connection->appendix);
                $this->afterAnswer($declaration->id, $client, (string) $connection->appendix);

                return;
            }
        } else {
            // Prête, mais une règle n'est plus remplie (n° BDTA contrôlé depuis le 09.11.2026, préparation
            // retirée de la liste de l'OSAV) : elle redevient à compléter. Relue sous verrou : une correction
            // enregistrée au même instant n'est jamais écrasée
            $isStale = DB::transaction(function () use ($declaration): bool {
                $current = AntibioticDeclaration::withoutGlobalScopes()->with('lines')->whereKey($declaration->id)->lockForUpdate()->first();
                if ($current?->status !== DeclarationStatus::Ready || $current->is_outcome_unknown || app(DeclarationRules::class)->missing($current) === []) {
                    return false;
                }
                $current->forceFill(['status' => DeclarationStatus::ToComplete])->save();

                return true;
            });
            if ($isStale) {
                return;
            }
            // Le token d'abord : refusé ou l'OSAV injoignable, rien n'est pris et elle reste modifiable
            $client->jwt();
            // Prise d'abord, d'un coup : dès lors elle ne se modifie plus (Declarations::update refuse),
            // et ce qui part est relu après la prise. Modifiée entre-temps : la tâche suivante l'enverra
            $claimed = AntibioticDeclaration::withoutGlobalScopes()->whereKey($declaration->id)
                ->where('status', DeclarationStatus::Ready)->where('is_outcome_unknown', false)
                ->update(['is_outcome_unknown' => true, 'attempts' => $declaration->attempts + 1, 'updated_at' => now()]);
            if ($claimed === 0) {
                return;
            }
            $declaration = $declaration->fresh(['lines', 'clinique.societe', 'source']);
        }
        $xml = (new DeclarationXml)->build($declaration, $declaration->clinique, $connection->appendix, $declaration->source?->getAttribute('numero'));
        $errors = DeclarationXml::schemaErrors($xml, $declaration->form);
        if ($errors !== []) {
            $declaration->forceFill(['status' => DeclarationStatus::Rejected, 'is_outcome_unknown' => false, 'osav_message' => 'La déclaration ne suit pas le schéma de l’OSAV : '.mb_substr(implode(' ; ', $errors), 0, 1500)])->save();

            return;
        }
        // Si la réponse se perd, le protocole sera lu avant tout nouvel envoi (is_outcome_unknown reste vrai)
        $this->record($declaration, $client->createPrescription($declaration->form, $xml), $connection->appendix);
        $this->afterAnswer($declaration->id, $client, (string) $connection->appendix);
    }

    /**
     * La réponse connue : une prise du traitement fini notée ou annulée pendant
     * l'envoi recompte ses jours ; annulée ou corrigée entre-temps et reçue par
     * l'OSAV, elle se désactive tout de suite (une nouvelle tâche pour elle se
     * heurterait au verrou d'unicité de celle-ci).
     */
    private function afterAnswer(int $declarationId, IsabvClient $client, string $clientAppendix): void
    {
        $after = AntibioticDeclaration::withoutGlobalScopes()->find($declarationId);
        if (in_array($after?->status, [DeclarationStatus::Sent, DeclarationStatus::Rejected], true) && $after->source_type === (new StayTreatment)->getMorphClass()
            && ($treatment = StayTreatment::query()->find($after->source_id)) !== null && Declarations::isTreatmentOver($treatment)) {
            app(Declarations::class)->treatmentStopped($treatment);
            $after = $after->fresh();
        }
        if ($after?->status !== DeclarationStatus::Replaced || $after->is_outcome_unknown) {
            return;
        }
        if ($after->sent_appendix !== null && $after->sent_appendix !== $clientAppendix) {
            // Partie sous un autre complément, que ce client ne couvre pas : à désactiver sur le site de l'OSAV
            $after->forceFill(['osav_message' => ($after->sent_appendix === '' ? 'Envoyée sans complément, sous l’ancien complément de la clinique' : 'Envoyée sous l’ancien complément « '.$after->sent_appendix.' »').' : désactivez-la dans l’application web de l’OSAV.'])->save();

            return;
        }
        $this->deactivate($after, $client);
    }

    private function record(AntibioticDeclaration $declaration, IsabvResult $result, ?string $appendix = null): void
    {
        // Sous verrou : une annulation au même instant attend la réponse, ou se voit ici (jamais écrasée)
        DB::transaction(function () use ($declaration, $result, $appendix): void {
            $current = AntibioticDeclaration::withoutGlobalScopes()->whereKey($declaration->id)->lockForUpdate()->first();
            if ($current === null) {
                return;
            }
            if ($current->status === DeclarationStatus::Replaced) {
                // Annulée pendant l'envoi : reçue, elle se désactive (afterAnswer) ; refusée, rien n'est à l'OSAV
                $current->forceFill($result->isOk
                    ? ['is_outcome_unknown' => false, 'osav_uuid' => $result->uuid, 'sent_at' => now(), 'sent_appendix' => (string) $appendix]
                    : ['is_outcome_unknown' => false, 'status' => DeclarationStatus::Deactivated, 'deactivated_at' => now()])->save();

                return;
            }
            $current->forceFill([
                'status' => $result->isOk ? DeclarationStatus::Sent : DeclarationStatus::Rejected,
                'osav_uuid' => $result->uuid,
                'osav_message' => $result->isOk
                    ? ($result->isDuplicate ? 'L’OSAV la tient pour un doublon et l’a écartée : si c’est une erreur, réactivez-la dans l’onglet Doublons.' : null)
                    : ($result->message ?? 'L’OSAV refuse cette déclaration.'),
                'ignored_fields' => $result->ignoredFields ?: null,
                'sent_at' => $result->isOk ? now() : null,
                'sent_appendix' => $result->isOk ? (string) ($appendix ?? $current->sent_appendix) : null,
                'is_outcome_unknown' => false,
            ])->save();
        });
    }
}
