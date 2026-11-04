<?php

namespace App\Mail;

use App\Models\Clinique;
use App\Models\CommandeFournisseur;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * L'avis d'un panier automatique, au moins 12 heures avant son départ
 * (décision de Jim, 04.11.2026), aux gestionnaires du stock : quand il part,
 * dit au jour près à l'heure de la clinique (« aujourd’hui », « demain »,
 * sinon le jour et la date), ce qu'il contient, et qu'on peut le modifier
 * ou le mettre en pause d'ici là. Minimum atteint, ou date butoir repoussée
 * faute d'avis à temps : l'avis le dit.
 */
class AutoBasketNotice extends Mailable
{
    use Queueable;

    /** « demain à 15:00 », fixé à l'envoi de l'avis */
    private string $when;

    public function __construct(
        public CommandeFournisseur $basket,
        public CarbonImmutable $departure,
        public float $total,
        public float $minimum,
        public string $rule,
        public bool $isMinimum = false,
        public bool $isPostponed = false,
    ) {
        $timezone = Clinique::withoutGlobalScopes()->whereKey($basket->clinique_id)->value('fuseau_horaire') ?: 'Europe/Zurich';
        $this->when = self::relative($departure, CarbonImmutable::now(), $timezone);
    }

    /**
     * Le départ dit au jour près, au fuseau de la clinique : les jours se
     * comparent en dates locales, le changement d'heure n'y change rien.
     */
    public static function relative(CarbonImmutable $moment, CarbonImmutable $now, string $timezone): string
    {
        $local = $moment->setTimezone($timezone);
        $today = $now->setTimezone($timezone)->toDateString();
        $hour = $local->format('H:i');

        return match ($local->toDateString()) {
            $today => 'aujourd’hui à '.$hour,
            $now->setTimezone($timezone)->addDay()->toDateString() => 'demain à '.$hour,
            default => $local->locale('fr')->isoFormat('dddd D MMMM').' à '.$hour,
        };
    }

    public function whenText(): string
    {
        return $this->when;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Panier automatique '.$this->basket->fournisseur.' : il part '.$this->when);
    }

    public function content(): Content
    {
        $isUnder = $this->minimum > 0 && $this->total < $this->minimum;

        return new Content(markdown: 'emails.commandes.panier-avis', with: [
            'quand' => $this->when,
            'total' => 'CHF '.number_format($this->total, 2, '.', '’'),
            'minimum' => $this->minimum > 0 ? 'CHF '.number_format($this->minimum, 2, '.', '’') : null,
            'pourquoi' => match (true) {
                $this->isPostponed => 'Sa date butoir était trop proche pour vous prévenir 12 heures avant : il part à la date butoir suivante.',
                $this->isMinimum => 'Le minimum de commande est atteint.',
                default => null,
            },
            'sousMinimum' => $isUnder ? match ($this->rule) {
                'send' => 'Il est sous le minimum : il partira quand même.',
                'wait' => 'Il est sous le minimum : il attendra la date suivante.',
                default => 'Il est sous le minimum : on vous demandera s’il faut l’envoyer.',
            } : null,
            'lignes' => $this->basket->lignes()->with(['produit' => fn ($p) => $p->withoutGlobalScopes()])->get(),
            'lien' => rtrim((string) config('app.frontend_url'), '/').'/clinique/stocks',
        ]);
    }
}
