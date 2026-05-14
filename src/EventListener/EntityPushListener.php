<?php

namespace App\EventListener;

use App\Message\PushDnsMessage;
use App\Message\PushDhcpMessage;
use App\Message\PushRadiusMessage;
use App\Service\PushScopeService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
#[AsDoctrineListener(event: Events::postFlush)]
class EntityPushListener
{
    /** @var array<int, true> Keyed by server ID for deduplication */
    private array $pendingDnsIds = [];
    private bool $pendingAllDhcp = false;
    private bool $pendingAllRadius = false;

    public function __construct(
        private readonly PushScopeService    $scope,
        private readonly MessageBusInterface $bus,
    ) {}

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->collect($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->collect($args->getObject());
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $this->collect($args->getObject());
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $dnsIds    = $this->pendingDnsIds;
        $allDhcp   = $this->pendingAllDhcp;
        $allRadius = $this->pendingAllRadius;

        // Reset before dispatching to avoid double-dispatch if flush triggers another flush
        $this->pendingDnsIds     = [];
        $this->pendingAllDhcp    = false;
        $this->pendingAllRadius  = false;

        foreach (array_keys($dnsIds) as $id) {
            $this->bus->dispatch(new PushDnsMessage($id));
        }

        if ($allDhcp) {
            foreach ($this->scope->allDhcpServerIds() as $id) {
                $this->bus->dispatch(new PushDhcpMessage($id));
            }
        }

        if ($allRadius) {
            foreach ($this->scope->allRadiusServerIds() as $id) {
                $this->bus->dispatch(new PushRadiusMessage($id));
            }
        }
    }

    private function collect(object $entity): void
    {
        foreach ($this->scope->dnsServerIdsFor($entity) as $id) {
            $this->pendingDnsIds[$id] = true;
        }

        if ($this->scope->affectsDhcp($entity)) {
            $this->pendingAllDhcp = true;
        }

        if ($this->scope->affectsRadius($entity)) {
            $this->pendingAllRadius = true;
        }
    }
}
