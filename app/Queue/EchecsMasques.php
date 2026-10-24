<?php

namespace App\Queue;

use App\Support\ExceptionMasquee;
use DateTimeInterface;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\PrunableFailedJobProvider;

/**
 * Les tâches en échec gardées sans valeurs (24.10.2026) : la table
 * `failed_jobs` recevait l'exception entière, dont le message du pilote
 * MySQL (« Duplicate entry 'adresse' ») et les arguments de la pile.
 * Enveloppe le fournisseur de Laravel (ou celui de Laravel Cloud) ; tout
 * le reste lui est transmis tel quel.
 */
final class EchecsMasques implements CountableFailedJobProvider, FailedJobProviderInterface, PrunableFailedJobProvider
{
    public function __construct(private FailedJobProviderInterface $fournisseur) {}

    public function log($connection, $queue, $payload, $exception)
    {
        return $this->fournisseur->log($connection, $queue, $payload, $exception instanceof ExceptionMasquee ? $exception : new ExceptionMasquee($exception));
    }

    public function ids($queue = null)
    {
        return $this->fournisseur->ids($queue);
    }

    public function all()
    {
        return $this->fournisseur->all();
    }

    public function find($id)
    {
        return $this->fournisseur->find($id);
    }

    public function forget($id)
    {
        return $this->fournisseur->forget($id);
    }

    public function flush($hours = null)
    {
        $this->fournisseur->flush($hours);
    }

    public function count($connection = null, $queue = null)
    {
        return $this->fournisseur instanceof CountableFailedJobProvider ? $this->fournisseur->count($connection, $queue) : count($this->fournisseur->all());
    }

    public function prune(DateTimeInterface $before)
    {
        return $this->fournisseur instanceof PrunableFailedJobProvider ? $this->fournisseur->prune($before) : 0;
    }
}
