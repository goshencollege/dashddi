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
        DhcpServer::class       => ['sshPrivateKey', 'controlPassword'],
        DnsServer::class        => ['sshPrivateKey', 'ddnsSecret'],
        SnipeItServer::class    => ['apiKey'],
    ];

    public function __construct(private readonly EncryptionService $encryption) {}

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

        if (!isset(self::FIELDS[$this->entityClass($entity)])) {
            return;
        }

        if ($this->encryptEntity($entity)) {
            $em       = $args->getObjectManager();
            $metadata = $em->getClassMetadata($this->entityClass($entity));
            $em->getUnitOfWork()->recomputeSingleEntityChangeSet($metadata, $entity);
        }
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->decryptEntity($args->getObject());
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $this->decryptEntity($args->getObject());
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
