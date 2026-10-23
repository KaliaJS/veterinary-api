<?php

namespace App\Services\Paiements;

use App\Auth\ContexteClinique;
use App\Enums\MoyenPaiement;
use App\Enums\StatutPaiement;
use App\Models\Facture;
use App\Models\Paiement;
use App\Models\TerminalPaiement;
use App\Models\User;
use App\Paiements\ErreurPaiement;
use App\Services\EncaissementFacture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Paiements de caisse par Stripe (docs/data-model.md §6) : carte sur le
 * terminal de la clinique, ou TWINT par QR, encaissés sur le compte de
 * paiement de sa société, la plateforme prenant sa commission. Chaque tentative est une
 * intention Stripe et une ligne `paiements` ; réussie, elle encaisse la
 * facture (même service que l'encaissement manuel : stock, statut).
 *
 * L'issue arrive par webhook ; la caisse interroge aussi `synchroniser`
 * (filet si le webhook tarde ou n'est pas configuré en local). Les deux
 * chemins passent par `appliquer`, verrouillé : la facture n'est
 * encaissée qu'une fois.
 */
class PaiementsCaisse
{
    public function __construct(
        private ComptesPaiement $comptes,
        private EncaissementFacture $encaissement,
        private ContexteClinique $contexte,
    ) {}

    /**
     * Envoie la facture au terminal : il affiche le montant et attend la
     * carte. Après un refus, la MÊME intention est représentée (règle
     * Stripe contre les doubles débits) tant que le montant n'a pas changé.
     */
    public function parTerminal(Facture $facture, TerminalPaiement $terminal, ?User $auteur): Paiement
    {
        $passerelle = $this->comptes->passerellePour($facture->clinique_id, pourEncaisser: true);
        $paiement = $this->tentativeARejouer($facture, $terminal);

        if (! $paiement) {
            $this->abandonnerEnCours($facture);

            $intention = $passerelle->creerIntentionTerminal(
                $this->centimes($facture),
                $this->metadonnees($facture, MoyenPaiement::Tpe),
                $this->cle($facture),
                ComptesPaiement::commission('terminal', $this->centimes($facture)),
            );

            // Enregistré AVANT d'envoyer au lecteur : un webhook rapide le retrouve
            $paiement = $this->creer($facture, MoyenPaiement::Tpe, $intention['id'], $auteur, [
                'terminal_paiement_id' => $terminal->id,
            ]);
        }

        try {
            $passerelle->presenterSurLecteur($terminal->stripe_reader_id, $paiement->stripe_payment_intent_id);
        } catch (ErreurPaiement $e) {
            $paiement->update(['statut' => StatutPaiement::Echoue, 'erreur' => $e->getMessage()]);
            $this->annulerIntentionSansEchec($paiement);

            throw $e;
        }

        return $paiement;
    }

    /** Intention TWINT : l'URL de la page TWINT devient le QR que scanne le client. */
    public function parTwint(Facture $facture, ?User $auteur): Paiement
    {
        $passerelle = $this->comptes->passerellePour($facture->clinique_id, pourEncaisser: true);
        $this->abandonnerEnCours($facture);

        $intention = $passerelle->creerIntentionTwint(
            $this->centimes($facture),
            $this->metadonnees($facture, MoyenPaiement::Twint),
            rtrim((string) config('app.frontend_url'), '/').'/paiement-termine',
            $this->cle($facture),
            ComptesPaiement::commission('twint', $this->centimes($facture)),
        );

        $paiement = $this->creer($facture, MoyenPaiement::Twint, $intention['id'], $auteur, [
            'url_twint' => $intention['url'],
        ]);

        return $this->appliquer($paiement, $intention['statut'], null);
    }

    /** Relit l'intention chez Stripe si le paiement attend encore. */
    public function synchroniser(Paiement $paiement): Paiement
    {
        if ($paiement->statut->estTermine()) {
            return $paiement;
        }

        $intention = $this->comptes->passerellePour($paiement->clinique_id)->lireIntention($paiement->stripe_payment_intent_id);

        return $this->appliquer($paiement, $intention['statut'], $intention['erreur']);
    }

    /**
     * Annulation par la caisse : libère le terminal puis annule
     * l'intention. Si Stripe refuse parce que le client vient de payer,
     * le paiement est appliqué comme réussi.
     */
    public function annuler(Paiement $paiement): Paiement
    {
        if ($paiement->statut->estTermine()) {
            return $paiement;
        }

        $passerelle = $this->comptes->passerellePour($paiement->clinique_id);

        if ($paiement->terminal) {
            try {
                $passerelle->annulerSurLecteur($paiement->terminal->stripe_reader_id);
            } catch (ErreurPaiement) {
                // Lecteur déjà libre ou hors ligne : l'intention fait foi
            }
        }

        try {
            $intention = $passerelle->annulerIntention($paiement->stripe_payment_intent_id);
        } catch (ErreurPaiement) {
            $intention = $passerelle->lireIntention($paiement->stripe_payment_intent_id);
        }

        return $this->appliquer($paiement, $intention['statut'], $intention['erreur']);
    }

    /** Le lecteur signale l'échec de son action (carte refusée, annulée sur le terminal…). */
    public function echecSurLecteur(Paiement $paiement, ?string $message): Paiement
    {
        return $this->verrouiller($paiement, function (Paiement $paiement) use ($message): void {
            if (! $paiement->statut->estTermine()) {
                $paiement->update([
                    'statut' => StatutPaiement::Echoue,
                    'erreur' => $message ?: 'Le paiement n’a pas abouti sur le terminal.',
                ]);
            }
        });
    }

    /**
     * Applique le statut Stripe d'une intention. Réussie : la facture est
     * encaissée (une seule fois, sous verrou).
     */
    public function appliquer(Paiement $paiement, string $statutStripe, ?string $erreur): Paiement
    {
        return $this->verrouiller($paiement, function (Paiement $paiement) use ($statutStripe, $erreur): void {
            if ($paiement->statut->estTermine() && $paiement->statut !== StatutPaiement::Echoue) {
                return;
            }

            match (true) {
                $statutStripe === 'succeeded' => $this->reussir($paiement),
                $statutStripe === 'canceled' => $paiement->update(['statut' => StatutPaiement::Annule]),
                $statutStripe === 'requires_payment_method' && $erreur !== null => $paiement->update([
                    'statut' => StatutPaiement::Echoue,
                    'erreur' => $erreur,
                ]),
                default => null,
            };
        });
    }

    private function reussir(Paiement $paiement): void
    {
        $paiement->update(['statut' => StatutPaiement::Reussi, 'erreur' => null, 'reussi_le' => now()]);

        $facture = $paiement->facture;

        if ($facture->estDue()) {
            $this->dansLaClinique($paiement, fn () => $this->encaissement->encaisser($facture, $paiement->moyen, $paiement->user));
        }
    }

    /**
     * Carte refusée juste avant, même montant : l'intention attend encore
     * une carte, on la représente sur le terminal choisi.
     */
    private function tentativeARejouer(Facture $facture, TerminalPaiement $terminal): ?Paiement
    {
        $echec = $facture->paiements()
            ->where('moyen', MoyenPaiement::Tpe)
            ->where('statut', StatutPaiement::Echoue)
            ->latest('id')
            ->first();

        if (! $echec || (float) $echec->montant !== $facture->loadMissing('lignes')->resteAPayer()
            || $facture->paiements()->where('id', '>', $echec->id)->exists()) {
            return null;
        }

        if ($this->comptes->passerellePour($facture->clinique_id)->lireIntention($echec->stripe_payment_intent_id)['statut'] !== 'requires_payment_method') {
            return null;
        }

        $echec->update(['statut' => StatutPaiement::EnAttente, 'erreur' => null, 'terminal_paiement_id' => $terminal->id]);

        return $echec;
    }

    /**
     * Une facture n'a qu'une tentative en cours : la précédente (caisse
     * refermée, client parti au TWINT…) est annulée avant d'en ouvrir une
     * autre ; l'intention d'une tentative refusée l'est aussi (pas de
     * double débit possible). Si un paiement avait abouti entre-temps, la
     * facture est payée.
     */
    private function abandonnerEnCours(Facture $facture): void
    {
        $facture->paiements()
            ->where('statut', StatutPaiement::EnAttente)
            ->get()
            ->each(fn (Paiement $paiement) => $this->annuler($paiement));

        $facture->paiements()
            ->where('statut', StatutPaiement::Echoue)
            ->get()
            ->each(fn (Paiement $paiement) => $this->annulerIntentionSansEchec($paiement));

        if (! $facture->refresh()->estDue()) {
            throw new ErreurPaiement('Cette facture vient d’être payée.');
        }
    }

    /** @param  array<string, mixed>  $champs */
    private function creer(Facture $facture, MoyenPaiement $moyen, string $intentionId, ?User $auteur, array $champs): Paiement
    {
        return Paiement::create([
            'facture_id' => $facture->id,
            'clinique_id' => $facture->clinique_id,
            'moyen' => $moyen,
            'montant' => $facture->resteAPayer(),
            'stripe_payment_intent_id' => $intentionId,
            'statut' => StatutPaiement::EnAttente,
            'user_id' => $auteur?->id,
            ...$champs,
        ]);
    }

    /** @param  callable(Paiement): void  $faire */
    private function verrouiller(Paiement $paiement, callable $faire): Paiement
    {
        DB::transaction(function () use ($paiement, $faire): void {
            $faire(Paiement::withoutGlobalScopes()->lockForUpdate()->findOrFail($paiement->id));
        });

        return $paiement->refresh();
    }

    /**
     * Le webhook arrive sans session : l'encaissement (sortie de stock)
     * se fait dans la clinique du paiement.
     *
     * @param  callable(): mixed  $faire
     */
    private function dansLaClinique(Paiement $paiement, callable $faire): void
    {
        if ($this->contexte->estDefini()) {
            $faire();

            return;
        }

        $this->contexte->definir($paiement->clinique()->withoutGlobalScopes()->firstOrFail());

        try {
            $faire();
        } finally {
            $this->contexte->oublier();
        }
    }

    private function annulerIntentionSansEchec(Paiement $paiement): void
    {
        try {
            $this->comptes->passerellePour($paiement->clinique_id)->annulerIntention($paiement->stripe_payment_intent_id);
        } catch (ErreurPaiement) {
            // Rien à annuler côté Stripe
        }
    }

    private function centimes(Facture $facture): int
    {
        // Le reste : l'acompte payé en ligne à la réservation est déjà encaissé
        return (int) round($facture->loadMissing('lignes')->resteAPayer() * 100);
    }

    /** @return array<string, string> */
    private function metadonnees(Facture $facture, MoyenPaiement $moyen): array
    {
        return [
            'facture_id' => (string) $facture->id,
            'facture_numero' => (string) $facture->numero,
            'clinique_id' => (string) $facture->clinique_id,
            'moyen' => $moyen->value,
        ];
    }

    private function cle(Facture $facture): string
    {
        return 'facture-'.$facture->id.'-'.Str::uuid();
    }
}
