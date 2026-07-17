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

    /** @return ApiToken[] General (non-host-scoped) tokens for the given owner */
    public function findGeneralByOwner(string $identifier): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.ownerIdentifier = :owner')
            ->andWhere('t.host IS NULL')
            ->setParameter('owner', $identifier)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return ApiToken[] Host-scoped tokens (all owners), ordered by host name */
    public function findAllHostScoped(): array
    {
        return $this->createQueryBuilder('t')
            ->join('t.host', 'h')
            ->where('t.host IS NOT NULL')
            ->orderBy('h.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
