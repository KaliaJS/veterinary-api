<?php

namespace App\Console\Commands;

use App\Enums\DeclarationStatus;
use App\Jobs\SendAntibioticDeclaration;
use App\Models\AntibioticDeclaration;
use App\Models\IsabvConnection;
use Illuminate\Console\Command;

/**
 * SI ABV : reprend ce qui attend (08.11.2026). Une déclaration prête, une
 * désactivation à faire, un envoi sans réponse lue : la tâche repart, pour
 * les cliniques qui déclarent par Veterinary (variante 1) avec un token accepté (la file
 * a pu se perdre, le token être recollé). Les déclarations doivent partir
 * au plus tard le 20 du mois suivant (O-SI ABV, art. 4, al. 2).
 */
class SendIsabvDeclarations extends Command
{
    protected $signature = 'isabv:send';

    protected $description = 'Relance les déclarations SI ABV qui attendent leur envoi ou leur désactivation';

    public function handle(): int
    {
        $connections = IsabvConnection::withoutGlobalScopes()->where('variant', IsabvConnection::VIA_VETERINARY)->whereNotNull('verified_at')->get(['clinique_id', 'appendix']);
        $appendices = $connections->mapWithKeys(fn (IsabvConnection $c) => [$c->clinique_id => (string) $c->appendix]);
        $ids = AntibioticDeclaration::withoutGlobalScopes()
            ->whereIn('clinique_id', $connections->pluck('clinique_id'))
            ->where(fn ($q) => $q->whereIn('status', [DeclarationStatus::Ready, DeclarationStatus::Replaced])
                ->orWhere(fn ($q) => $q->where('is_outcome_unknown', true)->whereNotIn('status', [DeclarationStatus::Sent, DeclarationStatus::Deactivated])))
            // Les toutes neuves partent déjà, après la fenêtre d'annulation
            ->where('updated_at', '<', now()->subMinutes(2))
            ->get(['id', 'clinique_id', 'status', 'sent_appendix'])
            // Une désactivation sous un ancien complément ne se refait pas : elle se fait sur le site de l'OSAV
            ->reject(fn (AntibioticDeclaration $d) => $d->status === DeclarationStatus::Replaced && $d->sent_appendix !== null && $d->sent_appendix !== $appendices->get($d->clinique_id))
            ->pluck('id');
        $ids->each(fn (int $id) => SendAntibioticDeclaration::dispatch($id));
        $this->info($ids->count().' déclaration(s) relancée(s)');

        return self::SUCCESS;
    }
}
