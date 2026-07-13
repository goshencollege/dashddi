<?php

namespace App\EventSubscriber;

use App\Entity\ArubaSwitch;
use App\Entity\BackupSetting;
use App\Entity\ClearpassServer;
use App\Entity\DhcpServer;
use App\Entity\DnsServer;
use App\Entity\SnipeItServer;
use App\Service\EncryptionService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postLoad)]
class EncryptedFieldSubscriber
{
    // entity class => list of property names to encrypt
    private const FIELDS = [
        ArubaSwitch::class      => ['password', 'sshPrivateKey'],
        BackupSetting::class    => ['backupPassword'],
        ClearpassServer::class  => ['clientSecret'],
        DhcpServer::class       => ['sshPrivateKey'],
        DnsServer::class        => ['sshPrivateKey', 'ddnsSecret'],
        SnipeItServer::class    => ['apiKey'],
    ];

    public function __construct(private readonly EncryptionService $encryption) {}

    /** Returns the list of property names that are encrypted for a given entity class. */
    public static function encryptedFieldsFor(string $class): array
    {
        return self::FIELDS[$class] ?? [];
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->encryptEntity($args->getObject());
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->decryptEntity($args->getObject());
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        $class  = $this->entityClass($entity);

        if (!isset(self::FIELDS[$class])) {
            return;
        }

        // Record which encrypted fields are genuinely dirty *before* we touch them.
        // Fields not in the changeset here have the same plaintext value as in the DB
        // and should not appear as changed after we re-encrypt them.
        $genuinelyChanged = [];
        foreach (self::FIELDS[$class] as $property) {
            if ($args->hasChangedField($property)) {
                $genuinelyChanged[$property] = true;
            }
        }

        if (!$this->encryptEntity($entity)) {
            return;
        }

        $em  = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        // For fields that were NOT genuinely changed, advance originalEntityData to the
        // new ciphertext so recomputeSingleEntityChangeSet does not mark them as dirty
        // (it would otherwise diff plaintext-original vs ciphertext-current and log a
        // spurious "[redacted] → [redacted]" audit entry on every flush).
        $originalData = $uow->getOriginalEntityData($entity);
        foreach (self::FIELDS[$class] as $property) {
            if (!isset($genuinelyChanged[$property])) {
                $getter = 'get' . ucfirst($property);
                $originalData[$property] = $entity->$getter();
            }
        }
        $uow->setOriginalEntityData($entity, $originalData);

        $uow->recomputeSingleEntityChangeSet($em->getClassMetadata($class), $entity);
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->decryptEntity($args->getObject());
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();
        $em     = $args->getObjectManager();

        $this->decryptEntity($entity);

        // After decrypting, update Doctrine's identity-map baseline to the plaintext values.
        // Without this, Doctrine compares plaintext (current) against ciphertext (original) on
        // every flush and marks the entity dirty, causing spurious UPDATEs and audit log entries.
        if ($em instanceof EntityManagerInterface && isset(self::FIELDS[$this->entityClass($entity)])) {
            $uow          = $em->getUnitOfWork();
            $originalData = $uow->getOriginalEntityData($entity);
            foreach (self::FIELDS[$this->entityClass($entity)] as $property) {
                $getter = 'get' . ucfirst($property);
                $originalData[$property] = $entity->$getter();
            }
            $uow->setOriginalEntityData($entity, $originalData);
        }
    }

    private function entityClass(object $entity): string
    {
        // Doctrine lazy proxies extend the real entity class; get_class() returns the proxy
        // class name which does not appear in FIELDS. Unwrap one level to get the real name.
        return $entity instanceof \Doctrine\Persistence\Proxy
            ? (get_parent_class($entity) ?: get_class($entity))
            : get_class($entity);
    }

    /** Encrypts all sensitive fields on the entity. Returns true if any field was changed. */
    private function encryptEntity(object $entity): bool
    {
        $fields = self::FIELDS[$this->entityClass($entity)] ?? [];
        $changed = false;

        foreach ($fields as $property) {
            $getter = 'get' . ucfirst($property);
            $setter = 'set' . ucfirst($property);
            $value  = $entity->$getter();

            if ($value !== null && $value !== '' && !$this->encryption->isEncrypted($value)) {
                $entity->$setter($this->encryption->encrypt($value));
                $changed = true;
            }
        }

        return $changed;
    }

    private function decryptEntity(object $entity): void
    {
        $fields = self::FIELDS[$this->entityClass($entity)] ?? [];

        foreach ($fields as $property) {
            $getter = 'get' . ucfirst($property);
            $setter = 'set' . ucfirst($property);
            $value  = $entity->$getter();

            if ($value !== null && $this->encryption->isEncrypted($value)) {
                $entity->$setter($this->encryption->decrypt($value));
            }
        }
    }
}
