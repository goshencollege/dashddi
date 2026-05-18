<?php

namespace App\EventListener;

use App\Message\PushClearpassMessage;
use App\Message\PushDhcpMessage;
use App\Message\PushDnsMessage;
use App\Service\PushScopeService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
#[AsDoctrineListener(event: Events::postFlush)]
class EntityPushListener
{
    /** @var array<int, true> Keyed by server ID for deduplication */
    private array $pendingDnsIds = [];
    /** @var array<string, true> Keyed by MAC for deduplication */
    private array $pendingClearpassMacs = [];
    private bool $pendingAllDhcp = false;

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
        $dnsIds        = $this->pendingDnsIds;
        $clearpassMacs = $this->pendingClearpassMacs;
        $allDhcp       = $this->pendingAllDhcp;
        // Reset before dispatching to avoid double-dispatch if flush triggers another flush
        $this->pendingDnsIds        = [];
        $this->pendingClearpassMacs = [];
        $this->pendingAllDhcp       = false;

        foreach (array_keys($dnsIds) as $id) {
            $this->bus->dispatch(new PushDnsMessage($id), [new DeduplicateStamp('push_dns_' . $id)]);
        }

        if (!empty($clearpassMacs)) {
            foreach ($this->scope->allClearpassServerIds() as $serverId) {
                foreach (array_keys($clearpassMacs) as $mac) {
                    $this->bus->dispatch(new PushClearpassMessage($serverId, $mac), [new DeduplicateStamp('push_clearpass_' . $serverId . '_' . $mac)]);
                }
            }
        }

        if ($allDhcp) {
            foreach ($this->scope->allDhcpServerIds() as $id) {
                $this->bus->dispatch(new PushDhcpMessage($id), [new DeduplicateStamp('push_dhcp_' . $id)]);
            }
        }

    }

    private function collect(object $entity): void
    {
        foreach ($this->scope->dnsServerIdsFor($entity) as $id) {
            $this->pendingDnsIds[$id] = true;
        }

        foreach ($this->scope->clearpassMacsFor($entity) as $mac) {
            $this->pendingClearpassMacs[$mac] = true;
        }

        if ($this->scope->affectsDhcp($entity)) {
            $this->pendingAllDhcp = true;
        }

    }
}
