<?php

namespace App\Repository;

use App\Entity\ActivityLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ActivityLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActivityLog::class);
    }

    /** @return ActivityLog[] */
    public function findFiltered(array $filters, int $limit, int $offset): array
    {
        $qb = $this->createQueryBuilder('a')->orderBy('a.createdAt', 'DESC');
        $this->applyFilters($qb, $filters);

        return $qb->setMaxResults($limit)->setFirstResult($offset)->getQuery()->getResult();
    }

    public function countFiltered(array $filters): int
    {
        $qb = $this->createQueryBuilder('a')->select('COUNT(a.id)');
        $this->applyFilters($qb, $filters);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function deleteOlderThan(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('a')
            ->delete()
            ->where('a.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    private function applyFilters(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['userIdentifier'])) {
            $qb->andWhere('a.userIdentifier LIKE :user')
               ->setParameter('user', '%' . $filters['userIdentifier'] . '%');
        }
        if (!empty($filters['entityType'])) {
            $qb->andWhere('a.entityType = :entityType')
               ->setParameter('entityType', $filters['entityType']);
        }
        if (!empty($filters['entityLabel'])) {
            $qb->andWhere('a.entityLabel LIKE :entityLabel')
               ->setParameter('entityLabel', '%' . $filters['entityLabel'] . '%');
        }
        if (!empty($filters['action'])) {
            $qb->andWhere('a.action = :action')
               ->setParameter('action', $filters['action']);
        }
        if (!empty($filters['dateFrom'])) {
            try {
                $qb->andWhere('a.createdAt >= :dateFrom')
                   ->setParameter('dateFrom', new \DateTimeImmutable($filters['dateFrom']));
            } catch (\Exception) {}
        }
        if (!empty($filters['dateTo'])) {
            try {
                $qb->andWhere('a.createdAt <= :dateTo')
                   ->setParameter('dateTo', new \DateTimeImmutable($filters['dateTo'] . ' 23:59:59'));
            } catch (\Exception) {}
        }
    }
}
