<?php

namespace App\EventListener;

use App\EventSubscriber\EncryptedFieldSubscriber;
use App\Message\SyslogMessage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::preRemove)]
#[AsDoctrineListener(event: Events::postFlush)]
class ActivityLogListener
{
    /** Rows pending DBAL insert, keyed by array with all column values. */
    private array $pending = [];

    private const ALWAYS_IGNORED = ['createdAt', 'createdBy', 'updatedAt', 'updatedBy', 'deletedAt', 'lastDhcpAt', 'lastDhcpIp', 'lastAuthAt', 'lastSyncAt', 'switchIp', 'switchPort'];

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly RequestStack          $requestStack,
        private readonly MessageBusInterface   $bus,
    ) {}

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$this->isAuditable($entity)) {
            return;
        }

        $this->pending[] = $this->buildPending('create', $entity, null);
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$this->isAuditable($entity)) {
            return;
        }

        $em        = $args->getObjectManager();
        $changeset = $em instanceof EntityManagerInterface
            ? $em->getUnitOfWork()->getEntityChangeSet($entity)
            : [];

        // Detect soft-delete and restore via the deletedAt field
        if (isset($changeset['deletedAt'])) {
            [$old, $new] = $changeset['deletedAt'];
            if ($old === null && $new !== null) {
                $this->pending[] = $this->buildPending('soft_delete', $entity, null);
                return;
            }
            if ($old !== null && $new === null) {
                $this->pending[] = $this->buildPending('restore', $entity, null);
                return;
            }
        }

        // Filter the changeset: remove audit-only fields, redact encrypted fields
        $encryptedFields = EncryptedFieldSubscriber::encryptedFieldsFor(get_class($entity));
        $fields          = [];

        foreach ($changeset as $field => [$old, $new]) {
            if (in_array($field, self::ALWAYS_IGNORED, true)) {
                continue;
            }
            if (in_array($field, $encryptedFields, true)) {
                $fields[$field] = ['[redacted]', '[redacted]'];
            } else {
                $fields[$field] = [$this->serializeValue($old), $this->serializeValue($new)];
            }
        }

        if (empty($fields)) {
            // Audit-only change (e.g. a second flush touching only updatedAt) — skip
            return;
        }

        $this->pending[] = $this->buildPending('update', $entity, $fields);
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledCollectionUpdates() as $collection) {
            $owner = $collection->getOwner();
            if ($owner === null || !$this->isAuditable($owner)) {
                continue;
            }

            $inserted = $collection->getInsertDiff();
            $deleted  = $collection->getDeleteDiff();
            if (empty($inserted) && empty($deleted)) {
                continue;
            }

            $fieldName   = $collection->getMapping()->fieldName;
            $currentItems = array_values($collection->toArray());

            // Reconstruct the pre-change list: current minus added, plus removed
            $oldItems = array_merge(
                array_values(array_filter($currentItems, fn($item) => !in_array($item, $inserted, true))),
                array_values($deleted),
            );

            $this->pending[] = $this->buildPending('update', $owner, [
                $fieldName => [$this->serializeCollection($oldItems), $this->serializeCollection($currentItems)],
            ]);
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$this->isAuditable($entity)) {
            return;
        }

        $em       = $args->getObjectManager();
        $snapshot = $em instanceof EntityManagerInterface
            ? $this->buildDeleteSnapshot($entity, $em)
            : null;

        $this->pending[] = $this->buildPending('delete', $entity, $snapshot ?: null);
    }

    private function buildDeleteSnapshot(object $entity, EntityManagerInterface $em): array
    {
        $originalData    = $em->getUnitOfWork()->getOriginalEntityData($entity);
        $encryptedFields = EncryptedFieldSubscriber::encryptedFieldsFor(get_class($entity));
        $snapshot        = [];

        foreach ($originalData as $field => $value) {
            if (in_array($field, self::ALWAYS_IGNORED, true)) {
                continue;
            }
            if ($value === null || $value === '' || $value instanceof \Doctrine\Common\Collections\Collection) {
                continue;
            }
            if (in_array($field, $encryptedFields, true)) {
                $snapshot[$field] = ['[redacted]', null];
            } else {
                $snapshot[$field] = [$this->serializeValue($value), null];
            }
        }

        return $snapshot;
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (empty($this->pending)) {
            return;
        }

        $rows          = $this->pending;
        $this->pending = [];

        $conn = $args->getObjectManager()->getConnection();
        $now  = new \DateTimeImmutable();

        foreach ($rows as $row) {
            $conn->insert('activity_log', [
                'action'          => $row['action'],
                'entity_type'     => $row['entity_type'],
                'entity_id'       => $row['entity_id'],
                'entity_label'    => $row['entity_label'],
                'user_identifier' => $row['user_identifier'],
                'ip_address'      => $row['ip_address'],
                'changed_fields'  => $row['changed_fields'] !== null
                    ? json_encode($row['changed_fields'])
                    : null,
                'created_at'      => $now->format('Y-m-d H:i:s'),
            ]);

            $this->bus->dispatch(new SyslogMessage(
                action:         $row['action'],
                entityType:     $row['entity_type'],
                entityId:       $row['entity_id'],
                entityLabel:    $row['entity_label'],
                userIdentifier: $row['user_identifier'],
                ipAddress:      $row['ip_address'],
                changedFields:  $row['changed_fields'],
                occurredAt:     $now,
            ));
        }
    }

    private function buildPending(string $action, object $entity, ?array $changedFields): array
    {
        return [
            'action'          => $action,
            'entity_type'     => (new \ReflectionClass($entity))->getShortName(),
            'entity_id'       => method_exists($entity, 'getId') ? $entity->getId() : null,
            'entity_label'    => $this->entityLabel($entity),
            'user_identifier' => $this->currentUserIdentifier(),
            'ip_address'      => $this->requestStack->getCurrentRequest()?->getClientIp(),
            'changed_fields'  => $changedFields,
        ];
    }

    private function isAuditable(object $entity): bool
    {
        return method_exists($entity, 'setCreatedAt');
    }

    private function entityLabel(object $entity): string
    {
        // Parent-context labels: "child @ parent"
        if (method_exists($entity, 'getDomain') && ($domain = $entity->getDomain()) !== null) {
            $name   = method_exists($entity, 'getHostname') ? (string) $entity->getHostname() : '';
            $parent = method_exists($domain, 'getName') ? (string) $domain->getName() : '';
            return $parent !== '' ? "{$name} @ {$parent}" : $name;
        }

        if (method_exists($entity, 'getHost') && ($host = $entity->getHost()) !== null) {
            $parentName = method_exists($host, 'getName') ? (string) $host->getName() : '';
            $name       = method_exists($entity, 'getName') ? ((string) $entity->getName()) : '';
            $identifier = $name !== '' ? $name
                : (method_exists($entity, 'getMacAddress') ? (string) $entity->getMacAddress() : '');
            return $parentName !== '' && $identifier !== '' ? "{$identifier} @ {$parentName}" : $identifier;
        }

        if (method_exists($entity, 'getSubnet') && ($subnet = $entity->getSubnet()) !== null) {
            $parentName = method_exists($subnet, 'getName') ? (string) $subnet->getName() : '';
            $name       = '';
            foreach (['getLabel', 'getHostname', 'getName'] as $m) {
                if (method_exists($entity, $m)) {
                    $v = (string) $entity->$m();
                    if ($v !== '') { $name = $v; break; }
                }
            }
            return $parentName !== '' && $name !== '' ? "{$name} @ {$parentName}" : $name;
        }

        // Generic fallback
        foreach (['getName', 'getHostname', 'getLabel', 'getTitle'] as $method) {
            if (method_exists($entity, $method)) {
                $value = $entity->$method();
                if ($value !== null && $value !== '') {
                    return (string) $value;
                }
            }
        }
        $id = method_exists($entity, 'getId') ? $entity->getId() : '?';
        return (new \ReflectionClass($entity))->getShortName() . ' #' . $id;
    }

    private function serializeValue(mixed $value): mixed
    {
        if ($value === null || is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value)) {
            return strlen($value) > 500 ? substr($value, 0, 500) . '…' : $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        if (is_object($value) && method_exists($value, 'getId')) {
            $id   = $value->getId();
            $name = null;
            foreach (['getName', 'getHostname'] as $m) {
                if (method_exists($value, $m)) {
                    $n = $value->$m();
                    if ($n !== null && $n !== '') { $name = (string) $n; break; }
                }
            }
            return $name !== null
                ? "{$name} (#{$id})"
                : (new \ReflectionClass($value))->getShortName() . '#' . $id;
        }
        if (is_array($value)) {
            return json_encode($value);
        }
        return (string) $value;
    }

    private function serializeCollection(array $items): string
    {
        $serialized = array_map(fn($item) => $this->serializeValue($item), $items);
        sort($serialized);
        return implode(', ', $serialized);
    }

    private function currentUserIdentifier(): ?string
    {
        $token = $this->tokenStorage->getToken();
        $user  = $token?->getUser();

        if ($user instanceof UserInterface) {
            return $user->getUserIdentifier();
        }

        $taskName = getenv('DASHDDI_SCHEDULED_TASK');
        return $taskName !== false && $taskName !== '' ? $taskName : null;
    }
}
