<?php

namespace App\Repository;

use App\Entity\DhcpServer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DhcpServerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DhcpServer::class);
    }

    /** @return int[] */
    public function findAllIds(): array
    {
        return array_column(
            $this->createQueryBuilder('s')
                ->select('s.id')
                ->getQuery()
                ->getArrayResult(),
            'id'
        );
    }
}
