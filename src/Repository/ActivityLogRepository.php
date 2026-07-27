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

        if (($filters['entityType'] ?? null) === 'Host' && !empty($filters['entityLabel'])) {
            $this->applyHostWithChildrenFilter($qb, $filters['entityLabel']);
        } else {
            if (!empty($filters['entityType'])) {
                $qb->andWhere('a.entityType = :entityType')
                   ->setParameter('entityType', $filters['entityType']);
            }
            if (!empty($filters['entityLabel'])) {
                $qb->andWhere('a.entityLabel LIKE :entityLabel')
                   ->setParameter('entityLabel', '%' . $filters['entityLabel'] . '%');
            }
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

    private function applyHostWithChildrenFilter(\Doctrine\ORM\QueryBuilder $qb, string $name): void
    {
        $em   = $this->getEntityManager();
        $like = '%' . $name . '%';

        $hostIds = $em->createQuery('SELECT h.id FROM App\Entity\Host h WHERE h.name LIKE :n')
            ->setParameter('n', $like)
            ->getSingleColumnResult();

        if (empty($hostIds)) {
            $qb->andWhere('1 = 0');
            return;
        }

        $niIds = $em->createQuery('SELECT ni.id FROM App\Entity\NetworkInterface ni WHERE ni.host IN (:ids)')
            ->setParameter('ids', $hostIds)
            ->getSingleColumnResult();

        $viIds = $em->createQuery('SELECT vi.id FROM App\Entity\VirtualIp vi JOIN vi.memberInterfaces ni WHERE ni.host IN (:ids)')
            ->setParameter('ids', $hostIds)
            ->getSingleColumnResult();

        $drIds = !empty($niIds)
            ? $em->createQuery('SELECT dr.id FROM App\Entity\DomainRecord dr WHERE dr.networkInterface IN (:ids)')
                  ->setParameter('ids', $niIds)
                  ->getSingleColumnResult()
            : [];

        if (!empty($viIds)) {
            $drViIds = $em->createQuery('SELECT dr.id FROM App\Entity\DomainRecord dr WHERE dr.virtualIp IN (:ids)')
                ->setParameter('ids', $viIds)
                ->getSingleColumnResult();
            $drIds = array_values(array_unique(array_merge($drIds, $drViIds)));
        }

        $atIds = $em->createQuery('SELECT at.id FROM App\Entity\ApiToken at WHERE at.host IN (:ids)')
            ->setParameter('ids', $hostIds)
            ->getSingleColumnResult();

        $orClauses = [
            $qb->expr()->andX('a.entityType = :etHost', 'a.entityId IN (:hostIds)'),
        ];
        $qb->setParameter('etHost', 'Host')->setParameter('hostIds', $hostIds);

        if (!empty($niIds)) {
            $orClauses[] = $qb->expr()->andX('a.entityType = :etNi', 'a.entityId IN (:niIds)');
            $qb->setParameter('etNi', 'NetworkInterface')->setParameter('niIds', $niIds);
        }
        if (!empty($viIds)) {
            $orClauses[] = $qb->expr()->andX('a.entityType = :etVi', 'a.entityId IN (:viIds)');
            $qb->setParameter('etVi', 'VirtualIp')->setParameter('viIds', $viIds);
        }
        if (!empty($drIds)) {
            $orClauses[] = $qb->expr()->andX('a.entityType = :etDr', 'a.entityId IN (:drIds)');
            $qb->setParameter('etDr', 'DomainRecord')->setParameter('drIds', $drIds);
        }
        if (!empty($atIds)) {
            $orClauses[] = $qb->expr()->andX('a.entityType = :etAt', 'a.entityId IN (:atIds)');
            $qb->setParameter('etAt', 'ApiToken')->setParameter('atIds', $atIds);
        }

        $qb->andWhere($qb->expr()->orX(...$orClauses));
    }
}
