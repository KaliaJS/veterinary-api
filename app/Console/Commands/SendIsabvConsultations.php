<?php

namespace App\Console\Commands;

use App\Models\Clinique;
use App\Models\IsabvConnection;
use App\Models\IsabvConsultationReport;
use App\Services\Isabv\ConsultationCounts;
use App\Services\Isabv\DeclarationXml;
use App\Services\Isabv\IsabvClient;
use App\Services\Isabv\IsabvTokenRefused;
use App\Services\Isabv\IsabvUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * SI ABV : le nombre de consultations de l'année écoulée part à l'OSAV
 * (08.11.2026), à déclarer au plus tard le 20 février (O-SI ABV, art. 4,
 * al. 2), pour chaque clinique qui déclare par Veterinary et ne l'a pas déjà
 * envoyé tel quel. Planifiée chaque matin du 1er au 19 février (une panne
 * se rattrape le lendemain) ; la page des déclarations l'envoie aussi à la
 * main.
 */
class SendIsabvConsultations extends Command
{
    protected $signature = 'isabv:send-consultations {--year= : année (l’année écoulée par défaut)}';

    protected $description = 'Envoie à l’OSAV le nombre de consultations par espèce de l’année écoulée';

    public function handle(ConsultationCounts $counts): int
    {
        $connections = IsabvConnection::withoutGlobalScopes()->where('variant', IsabvConnection::VIA_VETERINARY)->whereNotNull('verified_at')->get();
        foreach ($connections as $connection) {
            $clinique = Clinique::withoutGlobalScopes()->with('societe')->find($connection->clinique_id);
            $uid = $clinique ? DeclarationXml::uid($clinique) : null;
            if ($clinique === null || $uid === null || ! $connection->sendsThroughVeterinary()) {
                continue;
            }
            $year = (int) ($this->option('year') ?: CarbonImmutable::now($clinique->fuseau_horaire ?: 'Europe/Zurich')->year - 1);
            $sent = IsabvConsultationReport::withoutGlobalScopes()->where('clinique_id', $clinique->id)->where('year', $year)->where('status', 'sent')->pluck('count', 'species_code');
            $current = $counts->count($clinique, $year);
            if ($sent->count() === count($current) && collect($current)->every(fn (int $n, string $code) => (int) $sent->get($code) === $n)) {
                continue;
            }
            try {
                $counts->send($clinique, $year, IsabvClient::for($uid, $connection->appendix, (string) $connection->plainToken()));
                $this->info($clinique->nom.' : '.$year.' envoyé');
            } catch (IsabvTokenRefused) {
                $connection->forceFill(['verified_at' => null])->save();
                $this->warn($clinique->nom.' : token refusé par l’OSAV');
            } catch (IsabvUnavailable) {
                $this->warn($clinique->nom.' : l’OSAV ne répond pas, à reprendre');
            }
        }

        return self::SUCCESS;
    }
}
