<?php

namespace App\Repository;

use App\Entity\DomainAlias;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DomainAlias>
 */
class DomainAliasRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DomainAlias::class);
    }

    public function findByName(string $name): ?DomainAlias
    {
        return $this->findOneBy(['name' => $name]);
    }
}
