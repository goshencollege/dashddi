<?php

namespace App\EventListener;

use App\Entity\Host;
use App\Message\PushClearpassMessage;
use App\Message\PushDhcpMessage;
use App\Message\PushDnsMessage;
use App\Service\PushScopeService;
use App\Service\PushSuppressionContext;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

#[AsDoctrineListener(event: Events::onFlush)]
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
    /** @var array<string, true> MACs whose interfaces were just soft-deleted — dispatched with a separate dedup key so they are never blocked by a pending update message */
    private array $pendingClearpassDeleteMacs = [];
    private bool $pendingAllDhcp = false;

    public function __construct(
        private readonly PushScopeService       $scope,
        private readonly MessageBusInterface    $bus,
        private readonly PushSuppressionContext $suppression,
    ) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        $cols = array_merge(
            $uow->getScheduledCollectionUpdates(),
            $uow->getScheduledCollectionDeletions(),
        );

        foreach ($cols as $col) {
            $owner = $col->getOwner();
            if (!$owner instanceof Host || $col->getMapping()->fieldName !== 'tags') {
                continue;
            }
            foreach ($this->scope->clearpassMacsForHost($owner) as $mac) {
                $this->pendingClearpassMacs[$mac] = true;
            }
        }
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->collect($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        $em = $args->getObjectManager();
        if ($em instanceof EntityManagerInterface) {
            $changeset = $em->getUnitOfWork()->getEntityChangeSet($entity);
            if (isset($changeset['deletedAt'])
                && $changeset['deletedAt'][0] === null
                && $changeset['deletedAt'][1] !== null
            ) {
                // Soft-delete: route ClearPass through a separate dedup key so it cannot
                // be blocked by a pending update message for the same MAC.
                $this->collect($entity, includeClearpass: false);
                foreach ($this->scope->clearpassMacsFor($entity) as $mac) {
                    $this->pendingClearpassDeleteMacs[$mac] = true;
                }
                return;
            }

            // Activity-timestamp updates don't affect DNS, DHCP, or ClearPass config.
            // AuditListener also stamps updatedAt/updatedBy on every preUpdate, so
            // exclude those audit fields before checking whether anything real changed.
            $ignoredFields = ['lastAuthAt', 'lastDhcpAt', 'updatedAt', 'updatedBy'];
            if (!empty($changeset) && empty(array_diff(array_keys($changeset), $ignoredFields))) {
                return;
            }
        }

        $this->collect($entity);
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $this->collect($args->getObject());
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $dnsIds        = $this->pendingDnsIds;
        $clearpassMacs = $this->pendingClearpassMacs;
        $deleteMacs    = $this->pendingClearpassDeleteMacs;
        $allDhcp       = $this->pendingAllDhcp;
        // Reset before dispatching to avoid double-dispatch if flush triggers another flush
        $this->pendingDnsIds              = [];
        $this->pendingClearpassMacs       = [];
        $this->pendingClearpassDeleteMacs = [];
        $this->pendingAllDhcp             = false;

        foreach (array_keys($dnsIds) as $id) {
            $this->bus->dispatch(new PushDnsMessage($id), [new DeduplicateStamp('push_dns_' . $id)]);
        }

        if (!$this->suppression->isClearpassSuppressed()) {
            if (!empty($clearpassMacs)) {
                foreach ($this->scope->allClearpassServerIds() as $serverId) {
                    foreach (array_keys($clearpassMacs) as $mac) {
                        $this->bus->dispatch(new PushClearpassMessage($serverId, $mac), [new DeduplicateStamp('push_clearpass_' . $serverId . '_' . $mac)]);
                    }
                }
            }

            // Soft-delete pushes use a separate key so they are never blocked by a
            // pending regular-update message with the same MAC.
            if (!empty($deleteMacs)) {
                foreach ($this->scope->allClearpassServerIds() as $serverId) {
                    foreach (array_keys($deleteMacs) as $mac) {
                        $this->bus->dispatch(new PushClearpassMessage($serverId, $mac), [new DeduplicateStamp('push_clearpass_delete_' . $serverId . '_' . $mac)]);
                    }
                }
            }
        }

        if ($allDhcp) {
            foreach ($this->scope->allDhcpServerIds() as $id) {
                $this->bus->dispatch(new PushDhcpMessage($id), [new DeduplicateStamp('push_dhcp_' . $id)]);
            }
        }
    }

    private function collect(object $entity, bool $includeClearpass = true): void
    {
        foreach ($this->scope->dnsServerIdsFor($entity) as $id) {
            $this->pendingDnsIds[$id] = true;
        }

        if ($includeClearpass) {
            foreach ($this->scope->clearpassMacsFor($entity) as $mac) {
                $this->pendingClearpassMacs[$mac] = true;
            }
        }

        if ($this->scope->affectsDhcp($entity)) {
            $this->pendingAllDhcp = true;
        }
    }
}
