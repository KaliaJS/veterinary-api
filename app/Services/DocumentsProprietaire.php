<?php

namespace App\Services;

use App\Auth\ContexteProprietaire;
use App\Enums\CanalDevis;
use App\Enums\StatutDevis;
use App\Enums\StatutFacture;
use App\Enums\StatutOrdonnance;
use App\Models\Devis;
use App\Models\Document;
use App\Models\Facture;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Models\Vaccination;
use Illuminate\Support\Collection;

/**
 * « Documents & factures » du propriétaire : une liste unique, triée
 * du plus récent au plus ancien (les devis à signer d'abord) —
 * ordonnances, factures, devis du portail, carnet de vaccination par
 * animal, documents du dossier — avec, pour chacun, les gestes
 * possibles (signer, renouveler) et de quoi composer le PDF côté
 * front. Dérivés ici, jamais recalculés.
 */
class DocumentsProprietaire
{
    /** @return list<array<string, mixed>> */
    public function lister(ContexteProprietaire $contexte): array
    {
        $patientIds = $contexte->patientIds();
        $clientIds = $contexte->clientIds();

        if ($patientIds === [] && $clientIds === []) {
            return [];
        }

        return collect()
            ->concat($this->ordonnances($patientIds))
            ->concat($this->factures($clientIds))
            ->concat($this->devis($patientIds))
            ->concat($this->carnets($patientIds))
            ->concat($this->documents($patientIds))
            ->sortByDesc(fn (array $d) => (($d['a_signer'] ?? false) ? '9999' : '').($d['date'] ?? '').sprintf('%09d', $d['id']))
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $patientIds
     * @return Collection<int, covariant array<string, mixed>>
     */
    private function ordonnances(array $patientIds): Collection
    {
        if ($patientIds === []) {
            return collect();
        }

        return Ordonnance::withoutGlobalScopes()
            ->with(['patient', 'employe', 'clinique', 'lignes.produit', 'demandesRenouvellement'])
            ->whereIn('patient_id', $patientIds)
            ->orderByDesc('signee_le')
            ->get()
            ->map(function (Ordonnance $o): array {
                $enAttente = $o->demandesRenouvellement->whereNull('traitee_le')->isNotEmpty();
                $produits = $o->lignes->map(fn ($l) => $l->produit?->nom)->filter()->implode(', ');
                $active = $o->statut === StatutOrdonnance::Active;

                return [
                    'type' => 'ORDONNANCE',
                    'id' => $o->id,
                    'date' => $o->signee_le?->toDateString(),
                    'titre' => 'Ordonnance : '.($produits ?: $o->numero).' ('.$o->patient->nom.')',
                    'sous_ligne' => $enAttente
                        ? 'RENOUVELLEMENT DEMANDÉ · EN ATTENTE'
                        : mb_strtoupper(($o->employe?->nom ?? $o->clinique->nom).' · '.self::dateLongue($o->signee_le)).($active ? '' : ' · CLÔTURÉE'),
                    'peut_renouveler' => $active && $o->renouvellements > 0 && ! $enAttente,
                    'renouvellement_en_attente' => $enAttente,
                    'pdf' => [
                        'numero' => $o->numero,
                        'patient' => $o->patient->nom,
                        'espece' => $o->patient->espece?->libelle(),
                        'veterinaire' => $o->employe?->nom,
                        'clinique' => $o->clinique->nom,
                        'en_tete' => $o->clinique->enTeteDocument(),
                        'signee_le' => $o->signee_le?->toDateString(),
                        'note' => $o->note,
                        'lignes' => $o->lignes->map(fn ($l): array => [
                            'produit' => $l->produit?->nom ?? '—',
                            'posologie' => $l->posologie,
                            'duree' => $l->duree,
                        ])->values()->all(),
                    ],
                ];
            });
    }

    /**
     * @param  list<int>  $clientIds
     * @return Collection<int, covariant array<string, mixed>>
     */
    private function factures(array $clientIds): Collection
    {
        if ($clientIds === []) {
            return collect();
        }

        return Facture::withoutGlobalScopes()
            ->with(['lignes', 'clinique'])
            ->whereIn('client_id', $clientIds)
            ->orderByDesc('emise_le')
            ->get()
            ->map(function (Facture $f): array {
                $libelle = mb_strtolower($f->lignes->first()?->libelle ?? 'prestations');
                $payee = $f->statut === StatutFacture::Payee;

                return [
                    'type' => 'FACTURE',
                    'id' => $f->id,
                    'date' => $f->emise_le?->toDateString(),
                    'titre' => 'Facture '.$libelle.', CHF '.self::montant($f->totalTtc()).' ('.$f->patient_name.')',
                    'sous_ligne' => $payee
                        ? 'PAYÉE · '.mb_strtoupper(self::dateLongue($f->payee_le ?? $f->emise_le))
                        : 'À RÉGLER · '.mb_strtoupper(self::dateLongue($f->emise_le)),
                    'pdf' => [
                        'numero' => $f->numero,
                        'patient' => $f->patient_name,
                        'proprietaire' => $f->owner_name,
                        'clinique' => $f->clinique->nom,
                        'en_tete' => $f->clinique->enTeteDocument(),
                        'emise_le' => $f->emise_le?->toIso8601String(),
                        'statut' => $f->statut->value,
                        'payee_le' => $f->payee_le?->toIso8601String(),
                        'total_ttc' => round($f->totalTtc(), 2),
                        'montant_tva' => round($f->montantTva(), 2),
                        'total_ht' => $f->totalHt(),
                        'taux_tva' => Facture::TAUX_TVA * 100,
                        'lignes' => $f->lignes->map(fn ($l): array => ['libelle' => $l->libelle, 'quantite' => $l->quantite, 'prix_chf' => (float) $l->prix_chf, 'total_chf' => round($l->quantite * (float) $l->prix_chf, 2)])->values()->all(),
                    ],
                ];
            });
    }

    /**
     * @param  list<int>  $patientIds
     * @return Collection<int, covariant array<string, mixed>>
     */
    private function devis(array $patientIds): Collection
    {
        if ($patientIds === []) {
            return collect();
        }

        return Devis::withoutGlobalScopes()
            ->with(['patient', 'lignes'])
            ->whereIn('patient_id', $patientIds)
            ->where('canal', CanalDevis::Portail)
            ->whereIn('statut', [StatutDevis::Envoye, StatutDevis::Accepte, StatutDevis::Refuse])
            ->orderByDesc('emis_le')
            ->get()
            ->map(function (Devis $d): array {
                $effectif = $d->statutEffectif();
                $aSigner = $effectif === StatutDevis::Envoye->value;

                return [
                    'type' => 'DEVIS',
                    'id' => $d->id,
                    'date' => $d->emis_le?->toDateString(),
                    'titre' => 'Devis '.mb_strtolower($d->motif ?? $d->numero).', CHF '.self::montant($d->totalTtc()).' ('.$d->patient->nom.')',
                    'sous_ligne' => match ($effectif) {
                        StatutDevis::Envoye->value => 'EN ATTENTE DE VOTRE SIGNATURE',
                        StatutDevis::Accepte->value => 'SIGNÉ ÉLECTRONIQUEMENT'.($d->signe_le ? ' · '.mb_strtoupper(self::dateLongue($d->signe_le)) : ''),
                        StatutDevis::Refuse->value => 'REFUSÉ',
                        default => 'EXPIRÉ',
                    },
                    'a_signer' => $aSigner,
                    'pdf' => [
                        'numero' => $d->numero,
                        'patient' => $d->patient->nom,
                        'motif' => $d->motif,
                        'emis_le' => $d->emis_le?->toDateString(),
                        'valide_jusquau' => $d->valide_jusquau?->toDateString(),
                        'remise_pct' => $d->remise_pct,
                        'alea_pct' => $d->alea_pct,
                        'acompte_pct' => $d->acompte_pct,
                        'conditions' => $d->conditions,
                        'signe_le' => $d->signe_le?->toIso8601String(),
                        'signe_par' => $d->signe_par,
                        'sous_total' => round($d->sousTotal(), 2),
                        'total_ttc' => round($d->totalTtc(), 2),
                        'montant_tva' => round($d->montantTva(), 2),
                        'montant_remise' => $d->montantRemise(),
                        'montant_acompte' => $d->montantAcompte(),
                        'total_avec_alea' => $d->totalAvecAlea(),
                        'taux_tva' => Facture::TAUX_TVA * 100,
                        'lignes' => $d->lignes->map(fn ($l): array => ['libelle' => $l->libelle, 'quantite' => $l->quantite, 'prix_chf' => (float) $l->prix_chf, 'total_chf' => round($l->quantite * (float) $l->prix_chf, 2)])->values()->all(),
                    ],
                ];
            });
    }

    /**
     * @param  list<int>  $patientIds
     * @return Collection<int, covariant array<string, mixed>>
     */
    private function carnets(array $patientIds): Collection
    {
        if ($patientIds === []) {
            return collect();
        }

        return Patient::withoutGlobalScopes()
            ->with('vaccinations')
            ->whereIn('id', $patientIds)
            ->get()
            ->filter(fn (Patient $p): bool => $p->vaccinations->isNotEmpty())
            ->map(function (Patient $p): array {
                $derniere = $p->vaccinations->max('injectee_le');

                return [
                    'type' => 'CARNET',
                    'id' => $p->id,
                    'date' => $derniere?->toDateString(),
                    'titre' => 'Carnet de vaccination : '.$p->nom,
                    'sous_ligne' => 'MIS À JOUR LE '.mb_strtoupper(self::dateLongue($derniere)),
                    'pdf' => [
                        'patient' => $p->nom,
                        'espece' => $p->espece?->libelle(),
                        'race' => $p->race,
                        'puce' => $p->puce,
                        'vaccinations' => $p->vaccinations->sortByDesc('injectee_le')->values()->map(fn (Vaccination $v): array => [
                            'nom' => $v->nom,
                            'marque' => $v->marque,
                            'lot' => $v->lot,
                            'injectee_le' => $v->injectee_le?->toDateString(),
                            'echeance_le' => $v->echeance_le?->toDateString(),
                        ])->all(),
                    ],
                ];
            })
            ->values();
    }

    /**
     * @param  list<int>  $patientIds
     * @return Collection<int, covariant array<string, mixed>>
     */
    private function documents(array $patientIds): Collection
    {
        if ($patientIds === []) {
            return collect();
        }

        return Document::query()
            ->with('patient')
            ->whereIn('patient_id', $patientIds)
            ->orderByDesc('etabli_le')
            ->get()
            ->map(fn (Document $d): array => [
                'type' => mb_strtoupper($d->type ?: 'DOCUMENT'),
                'id' => $d->id,
                'date' => $d->etabli_le?->toDateString(),
                'titre' => $d->nom.' ('.$d->patient->nom.')',
                'sous_ligne' => mb_strtoupper(($d->signataire ? $d->signataire.' · ' : '').self::dateLongue($d->etabli_le)),
                'pdf' => null,
            ]);
    }

    private static function montant(float $v): string
    {
        return number_format($v, 2, '.', ' ');
    }

    private static function dateLongue(mixed $date): string
    {
        return $date ? $date->locale('fr_CH')->translatedFormat('j F Y') : '';
    }
}
