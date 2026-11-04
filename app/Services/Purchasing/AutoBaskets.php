<?php

namespace App\Services\Purchasing;

use App\Auth\SousContexteClinique;
use App\Enums\DomainePermission;
use App\Enums\StatutCommandeFournisseur;
use App\Enums\TypeAlerte;
use App\Mail\AutoBasketDecision;
use App\Mail\AutoBasketNotice;
use App\Mail\PurchaseOrderToSupplier;
use App\Models\Clinique;
use App\Models\CommandeFournisseur;
use App\Models\CommandeFournisseurLigne;
use App\Models\ProductSource;
use App\Models\Supplier;
use App\Services\Alertes\DestinatairesAlertes;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Les paniers automatiques qui partent (décisions de Jim, 03.11.2026) : dès
 * que le minimum du fournisseur est atteint, ou à sa date butoir. La veille,
 * les gestionnaires du stock sont prévenus (e-mail et alerte), et peuvent le
 * modifier ou le mettre en pause. Sous le minimum à la date butoir, la règle
 * de la clinique pour ce fournisseur : demander, envoyer quand même, ou
 * attendre la date suivante. Au moment d'envoyer par une centrale, un
 * article qu'elle ne peut pas livrer passe à sa source suivante ; sans autre
 * source, il reste à commander à la main.
 */
class AutoBaskets
{
    public function __construct(
        private PurchasingConnectors $connectors,
        private AutoOrdering $ordering,
        private PurchaseOrderPdf $pdf,
        private DestinatairesAlertes $readers,
        private SousContexteClinique $sousContexte,
    ) {}

    /** Le passage planifié : chaque panier automatique, un par un. */
    public function run(): int
    {
        $this->ordering->sweep();

        $sent = 0;
        CommandeFournisseur::withoutGlobalScopes()
            ->where('is_auto', true)
            ->where('statut', StatutCommandeFournisseur::Brouillon)
            ->whereNotNull('supplier_id')
            ->pluck('id')
            ->each(function (int $id) use (&$sent): void {
                rescue(function () use ($id, &$sent): void {
                    $sent += $this->decide($id) ? 1 : 0;
                });
            });

        return $sent;
    }

    /** Jamais de départ sans un avis envoyé au moins tant d'heures avant (décision de Jim, 04.11.2026) */
    public const int NOTICE_HOURS = 12;

    /**
     * Ce que devient un panier à ce passage ; vrai s'il est parti. Un panier
     * ne part jamais sans un avis envoyé au moins 12 heures avant son départ :
     * minimum atteint, le départ se fixe à la prochaine heure de commande qui
     * laisse ces 12 heures et l'avis part aussitôt ; une date butoir trop
     * proche pour l'avis passe à la suivante, et l'avis le dit. Les jours
     * fériés suisses ne sont pas gérés : une date butoir peut tomber un jour
     * férié (à reprendre quand Jim le demandera).
     */
    public function decide(int $basketId): bool
    {
        $basket = CommandeFournisseur::withoutGlobalScopes()->with(['supplier' => fn ($s) => $s->withoutGlobalScopes(), 'clinique' => fn ($c) => $c->withoutGlobalScopes()])->find($basketId);
        if ($basket === null || $basket->supplier === null || $basket->clinique === null) {
            return false;
        }

        return $this->sousContexte->pour($basket->clinique, function () use ($basket): bool {
            $supplier = $basket->supplier;
            // En pause, ou un envoi par une centrale resté sans réponse (tranché à la main) : il attend
            if ($basket->paused_at !== null || $this->pendingMessage($basket) !== null || $basket->lignes()->doesntExist()) {
                return false;
            }
            $now = CarbonImmutable::now();
            $earliest = $now->addHours(self::NOTICE_HOURS);
            $minimum = (float) $supplier->minimum_order;
            $isReached = $minimum > 0 && self::total($basket) >= $minimum;
            $departure = $basket->deadline_at !== null ? CarbonImmutable::instance($basket->deadline_at) : null;
            $isNoticed = $departure !== null && $basket->eve_notice_for !== null && $departure->equalTo($basket->eve_notice_for);

            // Minimum atteint : le départ à la prochaine heure de commande qui laisse 12 heures
            if ($isReached) {
                $target = $supplier->nextDeadline($earliest) ?? $earliest;
                if (! $isNoticed || $departure->greaterThan($target)) {
                    $this->announce($basket, $target, isMinimum: true);

                    return false;
                }
            }
            if ($departure === null) {
                return false;
            }
            if (! $isNoticed) {
                if ($now->greaterThanOrEqualTo($departure->subHours(self::NOTICE_HOURS))) {
                    // Trop proche pour l'avis : la date butoir suivante, et l'avis le dit
                    $this->announce($basket, $supplier->nextDeadline($earliest) ?? $earliest, isPostponed: true);
                } elseif ($now->greaterThanOrEqualTo($departure->subDay())) {
                    $this->announce($basket, $departure);
                }

                return false;
            }
            if ($now->lessThan($departure)) {
                return false;
            }
            if ($isReached || $minimum <= 0 || $supplier->under_minimum === 'send') {
                return $this->dispatch($basket, $departure);
            }
            if ($supplier->under_minimum === 'wait') {
                $basket->update(['deadline_at' => $supplier->nextDeadline($now), 'eve_notice_for' => null, 'decision_asked_for' => null]);

                return false;
            }
            $this->askOnce($basket, $departure, 'Le panier est sous le minimum de '.$supplier->name.' ('.self::chf(self::total($basket)).' sur '.self::chf($minimum).') à sa date butoir : envoyez-le quand même, ou attendez la date suivante.');

            return false;
        });
    }

    /** L'avis aux gestionnaires du stock (e-mail et alerte), et le départ qu'il annonce */
    private function announce(CommandeFournisseur $basket, CarbonImmutable $departure, bool $isMinimum = false, bool $isPostponed = false): void
    {
        $basket->update(['deadline_at' => $departure, 'eve_notice_for' => $departure, 'decision_asked_for' => null]);
        $supplier = $basket->supplier;
        $this->mailManagers($basket->clinique, new AutoBasketNotice(
            $basket->fresh() ?? $basket,
            $departure,
            self::total($basket),
            (float) $supplier->minimum_order,
            (string) $supplier->under_minimum,
            $isMinimum,
            $isPostponed,
        ));
    }

    /** Un envoi parti sans réponse chez la centrale du panier : ce qu'il faut dire, sinon null */
    public function pendingMessage(CommandeFournisseur $basket): ?string
    {
        $supplier = $basket->supplier()->withoutGlobalScopes()->first();
        $connector = $supplier?->channel === 'api' ? $this->connectors->get($supplier->connector) : null;

        return $connector?->pendingSend($basket);
    }

    /**
     * « Envoyer maintenant » (une personne le demande, et confirme) : par le
     * canal du fournisseur, sans attendre le minimum ni l'avis. Chez une
     * centrale, avec l'adresse et la livraison choisies. À la main : marqué
     * envoyé, le bon en PDF se télécharge depuis l'écran.
     *
     * @param  array{adresse_id?: string|null, livraison?: string, date_souhaitee?: string|null}  $choice
     *
     * @throws ValidationException quand il ne peut pas partir
     */
    public function sendNow(CommandeFournisseur $basket, array $choice = []): ?CommandeFournisseur
    {
        $supplier = $basket->supplier()->withoutGlobalScopes()->first();
        if ($supplier === null) {
            throw ValidationException::withMessages(['statut' => 'Ce panier n’a plus de fournisseur : envoyez la commande en PDF.']);
        }
        if ($supplier->channel === 'api') {
            try {
                $connector = $this->connectorFor($supplier);
                $this->moveUnavailable($basket, $connector);
                if ($basket->lignes()->doesntExist()) {
                    return null;
                }
                $connector->send($basket, $choice);
            } catch (PurchasingFailure $e) {
                throw ValidationException::withMessages(['statut' => $e->getMessage()]);
            }
            $basket->forceFill(['auto_error' => null, 'decision_asked_for' => null])->save();

            return $basket->refresh();
        }
        if ($supplier->channel === 'email') {
            $this->sendByEmail($basket, $supplier);

            return $basket->refresh();
        }
        $basket->forceFill(['statut' => StatutCommandeFournisseur::Envoyee, 'envoyee_le' => now(), 'auto_error' => null, 'decision_asked_for' => null])->save();

        return $basket->refresh();
    }

    /**
     * Les adresses du compte de la clinique chez la centrale du panier.
     *
     * @return list<array{id: string, libelle: string, par_defaut: bool}>
     *
     * @throws ValidationException
     */
    public function addresses(CommandeFournisseur $basket): array
    {
        $supplier = $basket->supplier()->withoutGlobalScopes()->first();
        if ($supplier === null || $supplier->channel !== 'api') {
            throw ValidationException::withMessages(['statut' => 'Ce panier ne part pas par une centrale d’achat.']);
        }
        try {
            return $this->connectorFor($supplier)->addresses((int) $basket->clinique_id);
        } catch (PurchasingFailure $e) {
            throw ValidationException::withMessages(['statut' => $e->getMessage()]);
        }
    }

    /** Le panier part par le canal de son fournisseur, sans personne derrière l'écran. */
    private function dispatch(CommandeFournisseur $basket, CarbonImmutable $moment): bool
    {
        $supplier = $basket->supplier;
        try {
            if ($supplier->channel === 'email') {
                $this->sendByEmail($basket, $supplier);

                return true;
            }
            if ($supplier->channel === 'api') {
                $connector = $this->connectorFor($supplier);
                // Envoyer seul passe une vraie commande : sans permission, il attend la clinique
                if (! config('purchasing.auto_send_api')) {
                    $this->askOnce($basket, $moment, 'Le panier de '.$supplier->name.' est prêt à partir ('.self::chf(self::total($basket)).') : l’envoi automatique par la centrale n’est pas encore permis, envoyez-le depuis Stocks, Commandes.');

                    return false;
                }
                $this->moveUnavailable($basket, $connector);
                if ($basket->lignes()->doesntExist()) {
                    return false;
                }
                $connector->send($basket);
                $basket->forceFill(['auto_error' => null, 'decision_asked_for' => null])->save();

                return true;
            }
            // À la main : la clinique transmet elle-même le bon
            $this->askOnce($basket, $moment, 'Le panier de '.$supplier->name.' est prêt ('.self::chf(self::total($basket)).') : envoyez le bon en PDF depuis Stocks, Commandes.');

            return false;
        } catch (PurchasingFailure|ValidationException $e) {
            $basket->forceFill(['auto_error' => mb_substr(self::message($e), 0, 255)])->save();

            return false;
        }
    }

    /** La centrale de ce fournisseur, reliée à la clinique, ou un refus dit à la clinique. */
    private function connectorFor(Supplier $supplier): PurchasingConnector
    {
        $connector = $this->connectors->get($supplier->connector);
        if ($connector === null) {
            throw new PurchasingFailure('La centrale de '.$supplier->name.' n’est plus connue : choisissez son canal dans Réglages, Fournisseurs.');
        }
        if (! $connector->isLinked((int) $supplier->clinique_id)) {
            throw new PurchasingFailure($connector->name().' n’est pas relié à cette clinique : reliez-le dans Réglages, Fournisseurs.');
        }

        return $connector;
    }

    /** Ce que la centrale ne peut pas livrer passe à la source suivante, ou reste à commander à la main. */
    private function moveUnavailable(CommandeFournisseur $basket, PurchasingConnector $connector): void
    {
        $out = $connector->unavailable($basket);
        if ($out === []) {
            return;
        }
        $clinique = Clinique::withoutGlobalScopes()->findOrFail($basket->clinique_id);
        $moved = $basket->moved_out ?? [];
        $lines = $basket->lignes()->with(['produit' => fn ($p) => $p->withoutGlobalScopes()])->whereIn('id', array_keys($out))->get();
        foreach ($lines as $line) {
            $produit = $line->produit;
            if ($produit === null) {
                continue;
            }
            // Une ligne manuelle déplacée reste manuelle : sans raison automatique (04.11.2026)
            $reason = $line->reason_at === null
                ? ['manual' => true]
                : ['stock' => (int) $line->reason_stock, 'threshold' => (int) $line->reason_threshold, 'at' => $line->reason_at];
            $quantity = (int) $line->quantite;
            $line->delete();

            $sources = $produit->sourcesIn($clinique->id);
            $current = $sources->first(fn (ProductSource $source): bool => (int) $source->supplier_id === (int) $basket->supplier_id);
            $rank = $current !== null ? $current->rank : 0;
            $next = $sources->first(fn (ProductSource $source): bool => $source->rank > $rank && (int) $source->supplier_id !== (int) $basket->supplier_id);
            $to = null;
            if ($next !== null && $this->ordering->addToBasket($produit, $clinique, $next, $reason, $basket->fournisseur, $quantity) !== null) {
                $to = $next->supplier !== null ? $next->supplier->name : null;
            } else {
                $this->ordering->markManual($produit, $clinique->id, $out[$line->id].', sans autre source');
            }
            $moved[] = ['produit_id' => $produit->id, 'name' => $produit->nom, 'to' => $to];
        }
        $basket->forceFill(['moved_out' => $moved])->save();
        // Plus rien à envoyer : le panier vide s'efface, les autres paniers disent d'où viennent les articles
        if ($basket->lignes()->doesntExist()) {
            $basket->delete();
        }
    }

    /** Le bon en PDF, par e-mail au fournisseur, copie à la clinique ; marqué envoyé. */
    private function sendByEmail(CommandeFournisseur $basket, Supplier $supplier): void
    {
        if (blank($supplier->email)) {
            throw ValidationException::withMessages(['statut' => 'L’adresse des commandes de '.$supplier->name.' manque : ajoutez-la dans Réglages, Fournisseurs.']);
        }
        DB::transaction(function () use ($basket, $supplier): void {
            $locked = CommandeFournisseur::withoutGlobalScopes()->whereKey($basket->id)->lockForUpdate()->firstOrFail();
            if ($locked->statut !== StatutCommandeFournisseur::Brouillon) {
                throw ValidationException::withMessages(['statut' => 'Seul un brouillon s’envoie.']);
            }
            $clinique = Clinique::withoutGlobalScopes()->findOrFail($locked->clinique_id);
            $mail = Mail::to((string) $supplier->email);
            if (filled($clinique->email)) {
                $mail->cc((string) $clinique->email);
            }
            $mail->send(new PurchaseOrderToSupplier($locked, $this->pdf->render($locked)));
            $locked->forceFill(['statut' => StatutCommandeFournisseur::Envoyee, 'envoyee_le' => now(), 'sent_to' => $supplier->email, 'auto_error' => null, 'decision_asked_for' => null])->save();
        });
    }

    /** Demander à la clinique, une fois par date butoir (ou par minimum atteint). */
    private function askOnce(CommandeFournisseur $basket, CarbonImmutable $moment, string $why): void
    {
        if ($basket->decision_asked_for !== null && ($basket->deadline_at === null || $moment->equalTo($basket->decision_asked_for))) {
            return;
        }
        $basket->forceFill(['decision_asked_for' => $moment])->save();
        $this->mailManagers($basket->clinique, new AutoBasketDecision($basket, $why));
    }

    private function mailManagers(Clinique $clinique, Mailable $mail): void
    {
        $to = $this->readers->pour($clinique, TypeAlerte::Stock, DomainePermission::Stocks);
        if ($to !== []) {
            Mail::to($to)->send($mail);
        }
    }

    /** Le total du panier, au centime, comme les totaux servis. */
    public static function total(CommandeFournisseur $basket): float
    {
        $cents = $basket->lignes()->get()->sum(fn (CommandeFournisseurLigne $line): int => (int) $line->quantite * (int) round((float) $line->cout * 100));

        return $cents / 100;
    }

    private static function chf(float $amount): string
    {
        return 'CHF '.number_format($amount, 2, '.', '’');
    }

    private static function message(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return (string) collect($e->errors())->flatten()->first();
        }

        return $e->getMessage();
    }

    /** @internal Une réponse HTTP d'une centrale (refus, panne) devient une panne du panier. */
    public static function failureFrom(Throwable $e): PurchasingFailure
    {
        if ($e instanceof PurchasingFailure) {
            return $e;
        }
        if ($e instanceof ValidationException) {
            return new PurchasingFailure(self::message($e), 0, $e);
        }
        if ($e instanceof HttpExceptionInterface) {
            return new PurchasingFailure($e->getMessage(), 0, $e);
        }

        return new PurchasingFailure('La centrale n’a pas pris le panier : '.$e->getMessage(), 0, $e);
    }
}
