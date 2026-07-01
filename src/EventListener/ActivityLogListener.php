<?php

namespace App\EventListener;

use App\EventSubscriber\EncryptedFieldSubscriber;
use App\Message\SyslogMessage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
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
#[AsDoctrineListener(event: Events::preRemove)]
#[AsDoctrineListener(event: Events::postFlush)]
class ActivityLogListener
{
    /** Rows pending DBAL insert, keyed by array with all column values. */
    private array $pending = [];

    private const ALWAYS_IGNORED = ['createdAt', 'createdBy', 'updatedAt', 'updatedBy', 'deletedAt'];

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

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$this->isAuditable($entity)) {
            return;
        }

        // Capture label and ID before the row is deleted
        $this->pending[] = $this->buildPending('delete', $entity, null);
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
        if ($value === null || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return strlen($value) > 500 ? substr($value, 0, 500) . '…' : $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if (is_object($value) && method_exists($value, 'getId')) {
            return (new \ReflectionClass($value))->getShortName() . '#' . $value->getId();
        }
        if (is_array($value)) {
            return json_encode($value);
        }
        return (string) $value;
    }

    private function currentUserIdentifier(): ?string
    {
        $token = $this->tokenStorage->getToken();
        $user  = $token?->getUser();
        return $user instanceof UserInterface ? $user->getUserIdentifier() : null;
    }
}
