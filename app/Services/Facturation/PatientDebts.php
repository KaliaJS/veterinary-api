<?php

namespace App\Services\Facturation;

use App\Enums\StatutFacture;
use App\Models\Facture;
use Illuminate\Support\Facades\DB;

/**
 * La dette d'un animal (03.11.2026) : le reste à payer de ses factures
 * dues chez son propriétaire actuel, toutes cliniques de l'organisation confondues. Gardée dans
 * `patients.dette` pour la liste (alerte « solde », filtre, tri), et
 * recalculée à chaque facture, ligne, paiement ou annulation qui touche
 * l'animal. Le solde dû d'un propriétaire se lit, lui, directement dans
 * ses factures (`ClientController`), factures sans animal comprises.
 */
class PatientDebts
{
    /** @param  iterable<int|null>  $patientIds */
    public static function refresh(iterable $patientIds): void
    {
        foreach (collect($patientIds)->filter()->unique() as $patientId) {
            // Les factures de son propriétaire actuel : un nouveau propriétaire
            // ne voit pas les dettes de l'ancien (règle de la fusion des dossiers)
            $ownerId = DB::table('patients')->where('id', $patientId)->value('client_id');
            $debt = Facture::withoutGlobalScopes()
                ->with(['lignes', 'settlements'])
                ->where('patient_id', $patientId)
                ->where('client_id', $ownerId)
                ->where('statut', StatutFacture::Due)
                ->get()
                ->sum(fn (Facture $facture): float => $facture->resteAPayer());

            // Sans passer par le modèle : la fiche de l'animal ne change pas de version
            DB::table('patients')->where('id', $patientId)->update(['dette' => round($debt, 2)]);
        }
    }

    /** Toutes les dettes, depuis les factures (une fois, et après une reprise de données). */
    public static function refreshAll(): int
    {
        DB::table('patients')->where('dette', '!=', 0)->update(['dette' => 0]);
        $ids = Facture::withoutGlobalScopes()->where('statut', StatutFacture::Due)->whereNotNull('patient_id')->distinct()->pluck('patient_id');
        self::refresh($ids);

        return $ids->count();
    }

    /**
     * Le reste à payer des factures dues d'un propriétaire, en SQL (liste et
     * fiche) : pour chaque facture, le total des lignes moins ses règlements
     * (acompte en ligne compris, 09.11.2026), jamais négatif, arrondi au centime.
     */
    public static function ownerBalanceSql(): string
    {
        // Sans table dérivée (MySQL avant 8.0.14 et MariaDB la refusent corrélée)
        $remaining = 'round(coalesce((select sum(fl.quantite * fl.prix_chf) from facture_lignes fl where fl.facture_id = f.id), 0) - coalesce((select sum(st.amount) from settlements st where st.facture_id = f.id), 0), 2)';

        return "coalesce((select sum(case when {$remaining} > 0 then {$remaining} else 0 end) from factures f where f.client_id = clients.id and f.statut = 'due'), 0)";
    }
}
