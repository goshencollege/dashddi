<?php

namespace App\Repository;

use App\Entity\DnsServer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DnsServerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DnsServer::class);
    }

    /** @return int[] IDs of servers that serve at least one of the given view IDs */
    public function findIdsByViewIds(array $viewIds): array
    {
        if (empty($viewIds)) {
            return [];
        }

        return array_column(
            $this->createQueryBuilder('s')
                ->select('s.id')
                ->join('s.views', 'v')
                ->where('v.id IN (:ids)')
                ->setParameter('ids', $viewIds)
                ->distinct()
                ->getQuery()
                ->getArrayResult(),
            'id'
        );
    }
}
