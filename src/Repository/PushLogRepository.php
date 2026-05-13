<?php

namespace App\Repository;

use App\Entity\PushLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PushLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushLog::class);
    }

    /** @return PushLog[] */
    public function findRecent(int $limit = 200): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.startedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function deleteOlderThan(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('p')
            ->delete()
            ->where('p.startedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
