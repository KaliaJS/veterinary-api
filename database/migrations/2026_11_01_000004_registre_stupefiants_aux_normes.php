<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le registre des stupéfiants mis aux normes (01.11.2026, demande de
     * Jim, art. 57, 63 et 64 OCStup, directives OSAV du 6.10.2025) :
     * - le produit dit son tableau (a ou b, OTStup-DFI) ;
     * - chaque ligne a son type (réception, administration, remise,
     *   perte, vol, retour, élimination, correction, inventaire, visa),
     *   le stock après elle, et les noms figés à l'inscription (animal,
     *   détenteur, signataire) : renommer quelqu'un ne change pas
     *   l'historique ;
     * - une correction pointe la ligne qu'elle corrige ;
     * - une réception porte le fournisseur et le bulletin ;
     * - un inventaire porte la quantité comptée ;
     * - trois décimales (0,125 ml).
     * Les lignes déjà inscrites reçoivent leur type, leur stock après et
     * leurs noms.
     */
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->string('tableau_stupefiant', 1)->nullable()->after('est_stupefiant')->comment('a ou b (OTStup-DFI)');
        });

        Schema::table('mouvements_stupefiants', function (Blueprint $table) {
            $table->string('type', 20)->default('administration')->after('employe_id');
            $table->decimal('quantite', 10, 3)->change();
            $table->decimal('solde_apres', 10, 3)->nullable()->after('quantite');
            $table->foreignId('corrige_id')->nullable()->after('solde_apres')->constrained('mouvements_stupefiants')->restrictOnDelete();
            $table->decimal('quantite_comptee', 10, 3)->nullable()->after('corrige_id');
            $table->string('patient_nom')->nullable();
            $table->string('detenteur_nom')->nullable();
            $table->string('signataire_nom')->nullable();
            $table->string('ordonnance_numero', 30)->nullable();
            $table->string('fournisseur')->nullable();
            $table->string('bulletin', 60)->nullable();
            $table->date('bulletin_le')->nullable();
        });

        // Les lignes déjà inscrites : leur type, leur stock après, leurs noms
        $soldes = [];
        DB::table('mouvements_stupefiants')->orderBy('created_at')->orderBy('id')->get()->each(function (object $m) use (&$soldes): void {
            $cle = $m->clinique_id.'-'.$m->produit_id;
            $soldes[$cle] = round(($soldes[$cle] ?? 0) + (float) $m->quantite, 3);
            $patient = $m->patient_id ? DB::table('patients')->where('id', $m->patient_id)->first(['nom', 'client_id']) : null;
            $client = $patient?->client_id ? DB::table('clients')->where('id', $patient->client_id)->first(['civilite', 'prenom', 'nom']) : null;
            DB::table('mouvements_stupefiants')->where('id', $m->id)->update([
                'type' => self::typeAncien((float) $m->quantite, $m->patient_id !== null, (string) $m->motif),
                'quantite_comptee' => self::typeAncien((float) $m->quantite, $m->patient_id !== null, (string) $m->motif) === 'inventaire' ? $soldes[$cle] : null,
                'solde_apres' => $soldes[$cle],
                'patient_nom' => $patient?->nom,
                'detenteur_nom' => $client ? trim(($client->civilite ?? '').' '.$client->nom) : null,
                'signataire_nom' => $m->employe_id ? DB::table('employes')->where('id', $m->employe_id)->value('nom') : null,
            ]);
        });
    }

    /**
     * Le type d'une ligne d'avant la mise aux normes, d'après son sens et son
     * motif (vérification du 01.11.2026) : un report d'ouverture est un
     * inventaire, un vol un vol, un flacon cassé une perte, une destruction
     * une élimination ; une entrée sans réception ni report, ou une sortie
     * sans animal ni motif connu, une correction.
     */
    private static function typeAncien(float $quantite, bool $versUnAnimal, string $motif): string
    {
        $m = mb_strtolower($motif);

        return match (true) {
            $versUnAnimal => 'administration',
            str_contains($m, 'report') || str_contains($m, 'ouverture') => 'inventaire',
            $quantite > 0 && (str_contains($m, 'récep') || str_contains($m, 'recep') || str_contains($m, 'grossiste') || str_contains($m, 'commande') || str_contains($m, 'lot ')) => 'reception',
            str_contains($m, 'vol') => 'vol',
            str_contains($m, 'cass') || str_contains($m, 'perte') || str_contains($m, 'perdu') || str_contains($m, 'renvers') => 'perte',
            str_contains($m, 'destruct') || str_contains($m, 'périm') || str_contains($m, 'elimin') || str_contains($m, 'élimin') => 'elimination',
            $quantite > 0 && blank($m) => 'reception',
            default => 'correction',
        };
    }

    public function down(): void
    {
        Schema::table('mouvements_stupefiants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('corrige_id');
            $table->dropColumn(['type', 'solde_apres', 'quantite_comptee', 'patient_nom', 'detenteur_nom', 'signataire_nom', 'ordonnance_numero', 'fournisseur', 'bulletin', 'bulletin_le']);
            $table->decimal('quantite', 8, 2)->change();
        });
        Schema::table('produits', function (Blueprint $table) {
            $table->dropColumn('tableau_stupefiant');
        });
    }
};
