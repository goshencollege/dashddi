<?php

namespace App\Repository;

use App\Entity\ApiToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    public function findByTokenHash(string $hash): ?ApiToken
    {
        return $this->findOneBy(['tokenHash' => $hash]);
    }

    /** @return ApiToken[] */
    public function findByOwner(string $identifier): array
    {
        return $this->findBy(['ownerIdentifier' => $identifier], ['createdAt' => 'DESC']);
    }
}
