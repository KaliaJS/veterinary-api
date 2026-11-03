<?php

namespace App\Support;

use App\Enums\StatutDevis;
use App\Models\Client;
use App\Models\Clinique;
use Illuminate\Support\Facades\DB;

/**
 * Les pièces émises avant l'en-tête figé (02.11.2026) le reçoivent une
 * fois, d'après la fiche actuelle (la meilleure source : aucune copie n'a
 * été gardée). Seules les pièces sans en-tête sont touchées : relancé, rien
 * ne bouge. Un devis encore en brouillon n'est pas émis et reste tel quel.
 * Écrit sans passer par les modèles : ni horodatage ni événement.
 */
final class FreezeExistingHeaders
{
    public function run(): void
    {
        $this->freeze('factures', fn (object $piece): ?int => $piece->client_id);
        $this->freeze('ordonnances', fn (object $piece): ?int => $this->clientDuPatient($piece->patient_id));
        $this->freeze('devis', fn (object $piece): ?int => $this->clientDuPatient($piece->patient_id), StatutDevis::Brouillon->value);
    }

    /** @param  callable(object): ?int  $clientDe */
    private function freeze(string $table, callable $clientDe, ?string $saufStatut = null): void
    {
        DB::table($table)
            ->whereNull('issuer')
            ->when($saufStatut !== null, fn ($requete) => $requete->where('statut', '!=', $saufStatut))
            ->orderBy('id')
            ->chunkById(200, function ($pieces) use ($table, $clientDe): void {
                foreach ($pieces as $piece) {
                    $clientId = $clientDe($piece);
                    $client = $clientId === null ? null : Client::withoutGlobalScopes()->find($clientId);
                    $clinique = Clinique::withoutGlobalScopes()->with('societe')->find($piece->clinique_id);
                    DB::table($table)->where('id', $piece->id)->update([
                        'recipient' => self::json(DocumentHeader::recipient($client)),
                        'issuer' => self::json(DocumentHeader::issuer($clinique)),
                    ]);
                }
            });
    }

    /** @param  array<string, mixed>|null  $valeur */
    private static function json(?array $valeur): ?string
    {
        return $valeur === null ? null : json_encode($valeur, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function clientDuPatient(?int $patientId): ?int
    {
        return $patientId === null ? null : DB::table('patients')->where('id', $patientId)->value('client_id');
    }
}
