<?php

namespace App\Repository;

use App\Entity\Domain;
use App\Entity\DomainRecord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DomainRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DomainRecord::class);
    }

    public function searchPaginated(Domain $domain, string $q, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.domain = :domain')
            ->setParameter('domain', $domain)
            ->orderBy('r.hostname', 'ASC')
            ->addOrderBy('r.type', 'ASC');

        if ($q !== '') {
            $qb->andWhere('r.hostname LIKE :q OR r.value LIKE :q')
               ->setParameter('q', '%' . $q . '%');
        }

        $total = (int) (clone $qb)
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $records = $qb
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['records' => $records, 'total' => $total];
    }
}
