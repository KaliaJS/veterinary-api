<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les règlements des factures (demande de Jim, 09.11.2026) : une ligne
 * par paiement reçu, datée du jour où l'argent est arrivé ; une ligne
 * négative pour une annulation ou un remboursement, jamais d'effacement.
 * Le reste à payer et le statut de la facture s'en déduisent.
 *
 * Reprise : chaque facture payée reçoit le règlement de ce qui est passé
 * à la caisse (son reste), à sa date de paiement, avec son moyen et sa
 * clôture de caisse (le prochain Z ne la recompte pas) ; chaque acompte en
 * ligne devient un règlement « acompte » daté de son paiement. Ce qui a
 * été rendu avant la reprise (acompte rendu après la facture, paiement
 * remboursé chez Stripe en tout ou en partie, litige perdu) devient une
 * ligne négative, et une facture payée dont l'argent est reparti redevient
 * due (vérification du 09.11.2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('clinique_id')->constrained()->restrictOnDelete();
            // La facture ne s'efface que dans la fenêtre d'annulation de sa création, sans règlement de caisse
            $table->foreignId('facture_id')->constrained()->cascadeOnDelete();
            $table->string('method', 12)->comment('tpe, twint, cash, virement, acompte (payé en ligne à la réservation)');
            $table->decimal('amount', 10, 2)->comment('Négatif : annulation ou remboursement');
            $table->date('received_on')->comment('Jour où l’argent est arrivé (ou reparti), à l’heure de la clinique');
            $table->string('reference', 140)->nullable()->comment('Référence du virement, facultative');
            $table->foreignId('paiement_id')->nullable()->comment('Paiement Stripe (carte, TWINT) dont il vient')->constrained('paiements')->nullOnDelete();
            $table->foreignId('reverses_id')->nullable()->unique()->comment('Le règlement que cette ligne annule')->constrained('settlements')->restrictOnDelete();
            $table->string('reason', 300)->nullable()->comment('Motif de l’annulation, ou cause du remboursement');
            $table->foreignId('cloture_caisse_id')->nullable()->constrained('clotures_caisse')->nullOnDelete();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['facture_id', 'received_on']);
            $table->index(['clinique_id', 'cloture_caisse_id']);
            $table->index(['clinique_id', 'received_on']);
            $table->index('paiement_id');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }

    private function backfill(): void
    {
        $zones = DB::table('cliniques')->pluck('fuseau_horaire', 'id');
        $jour = fn (?string $utc, int $clinique): ?string => $utc === null ? null
            : CarbonImmutable::parse($utc, 'UTC')->setTimezone($zones[$clinique] ?: 'Europe/Zurich')->toDateString();
        // « Remboursé en partie : CHF 1’234.50. » : le montant rendu, en francs
        $montantDe = fn (?string $texte): float => preg_match('/CHF\s*([\d’\'.]+)/u', (string) $texte, $m) === 1
            ? (float) rtrim(str_replace(['’', "'"], '', $m[1]), '.') : 0.0;

        DB::table('factures')
            ->where(fn ($q) => $q->where('statut', 'payee')->orWhere('acompte', '>', 0))
            ->orderBy('id')
            ->select(['id', 'organisation_id', 'clinique_id', 'statut', 'moyen_paiement', 'acompte', 'payee_le', 'emise_le', 'rendez_vous_id', 'cloture_caisse_id', 'encaissee_par_id'])
            ->chunkById(500, function ($factures) use ($jour, $montantDe): void {
                $totaux = DB::table('facture_lignes')->whereIn('facture_id', $factures->pluck('id'))
                    ->groupBy('facture_id')->selectRaw('facture_id, sum(quantite * prix_chf) as total')->pluck('total', 'facture_id');
                $demandes = DB::table('demandes_rendez_vous')
                    ->whereIn('rendez_vous_id', $factures->pluck('rendez_vous_id')->filter())
                    ->orderBy('id')
                    ->get(['rendez_vous_id', 'acompte_centimes', 'acompte_paye_le', 'acompte_rembourse_le', 'acompte_rembourse_centimes'])
                    ->keyBy('rendez_vous_id');
                // Le paiement Stripe qui a payé la facture : réussi ou en litige, sinon remboursé
                // après coup chez Stripe (pas un paiement rendu aussitôt parce qu'en trop) ; le premier
                $paiements = DB::table('paiements')->whereIn('facture_id', $factures->pluck('id'))
                    ->whereIn('statut', ['reussi', 'litige', 'rembourse'])->orderBy('id')
                    ->get(['id', 'facture_id', 'statut', 'montant', 'erreur', 'updated_at'])
                    ->groupBy('facture_id');
                $now = now();
                $lignes = [];
                $redues = [];

                foreach ($factures as $f) {
                    $total = round((float) ($totaux[$f->id] ?? 0), 2);
                    // L'acompte entier : c'est l'argent reçu (une facture encore vide a un total nul)
                    $acompte = round((float) $f->acompte, 2);
                    $commun = ['organisation_id' => $f->organisation_id, 'clinique_id' => $f->clinique_id, 'facture_id' => $f->id, 'reference' => null, 'reason' => null, 'paiement_id' => null, 'cloture_caisse_id' => null, 'recorded_by_id' => null, 'created_at' => $now, 'updated_at' => $now];
                    $regle = 0.0;

                    if ($acompte > 0) {
                        $demande = $f->rendez_vous_id !== null ? ($demandes[$f->rendez_vous_id] ?? null) : null;
                        $lignes[] = ['method' => 'acompte', 'amount' => $acompte, 'received_on' => $jour($demande->acompte_paye_le ?? $f->emise_le, $f->clinique_id)] + $commun;
                        $regle += $acompte;
                        // Rendu après la facture : ce qui n'en reste plus, en négatif, daté du rendu
                        if ($demande !== null && $demande->acompte_paye_le !== null && $demande->acompte_rembourse_le !== null) {
                            $retenu = max(0, (int) $demande->acompte_centimes - (int) ($demande->acompte_rembourse_centimes ?? $demande->acompte_centimes)) / 100;
                            if ($retenu < $acompte - 0.005) {
                                $lignes[] = ['method' => 'acompte', 'amount' => -round($acompte - $retenu, 2), 'received_on' => $jour($demande->acompte_rembourse_le, $f->clinique_id), 'reason' => 'Acompte rendu au propriétaire'] + $commun;
                                $regle -= round($acompte - $retenu, 2);
                            }
                        }
                    }

                    $reste = max(0.0, round($total - $acompte, 2));
                    if ($f->statut === 'payee' && $reste > 0 && $f->moyen_paiement !== null) {
                        $stripe = in_array($f->moyen_paiement, ['tpe', 'twint'], true) ? collect($paiements[$f->id] ?? []) : collect();
                        $preuve = $stripe->first(fn ($p) => in_array($p->statut, ['reussi', 'litige'], true))
                            ?? $stripe->first(fn ($p) => $p->statut === 'rembourse' && preg_match('/^(Remboursé \(|Litige perdu)/u', (string) $p->erreur) === 1);
                        $lignes[] = [
                            'method' => $f->moyen_paiement, 'amount' => $reste, 'received_on' => $jour($f->payee_le ?? $f->emise_le, $f->clinique_id),
                            'paiement_id' => $preuve?->id, 'cloture_caisse_id' => $f->cloture_caisse_id, 'recorded_by_id' => $f->encaissee_par_id,
                        ] + $commun;
                        $regle += $reste;
                        // Remboursé chez Stripe avant la reprise : en entier (ou litige perdu), ou en partie
                        $rendu = $preuve === null ? 0.0 : ($preuve->statut === 'rembourse' ? $reste : min($reste, $montantDe(str_starts_with((string) $preuve->erreur, 'Remboursé en partie') ? $preuve->erreur : null)));
                        if ($rendu > 0.005) {
                            $lignes[] = [
                                'method' => $f->moyen_paiement, 'amount' => -round($rendu, 2), 'received_on' => $jour($preuve->updated_at, $f->clinique_id),
                                'paiement_id' => $preuve->id, 'reason' => str_starts_with((string) $preuve->erreur, 'Litige perdu') ? 'Litige perdu' : 'Remboursé chez Stripe',
                            ] + $commun;
                            $regle -= round($rendu, 2);
                        }
                    }

                    // Payée, mais l'argent est reparti : de nouveau due (décision de Jim, 09.11.2026)
                    if ($f->statut === 'payee' && round($total - $regle, 2) > 0.005) {
                        $redues[] = $f->id;
                    }
                }

                if ($lignes !== []) {
                    DB::table('settlements')->insert($lignes);
                }
                if ($redues !== []) {
                    DB::table('factures')->whereIn('id', $redues)->update(['statut' => 'due', 'payee_le' => null, 'moyen_paiement' => null]);
                }
            });
    }
};
