<?php

namespace App\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
class AuditListener
{
    /**
     * Fields whose changes are system-generated and should not count as a
     * user edit — mirrors the system-only subset of ActivityLogListener::ALWAYS_IGNORED.
     */
    private const SYSTEM_FIELDS = ['lastDhcpAt', 'lastDhcpIp', 'lastAuthAt', 'lastSyncAt', 'lastUsedAt', 'switchIp', 'switchPort'];

    public function __construct(private readonly TokenStorageInterface $tokenStorage) {}

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!method_exists($entity, 'setCreatedAt')) {
            return;
        }

        $now  = new \DateTimeImmutable();
        $user = $this->currentUserIdentifier();

        $entity->setCreatedAt($now);
        $entity->setUpdatedAt($now);
        $entity->setCreatedBy($user);
        $entity->setUpdatedBy($user);
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!method_exists($entity, 'setUpdatedAt')) {
            return;
        }

        $userChangedFields = array_diff_key($args->getEntityChangeSet(), array_flip(self::SYSTEM_FIELDS));
        if (empty($userChangedFields)) {
            return;
        }

        $entity->setUpdatedAt(new \DateTimeImmutable());
        $entity->setUpdatedBy($this->currentUserIdentifier());
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
