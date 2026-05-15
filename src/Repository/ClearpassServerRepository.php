<?php

namespace App\Repository;

use App\Entity\ClearpassServer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ClearpassServerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClearpassServer::class);
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
