<?php

namespace App\Repository;

use App\Entity\AddressBlock;
use App\Enum\BlockType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AddressBlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AddressBlock::class);
    }

    /** @return AddressBlock[] */
    public function findBySubnet(int $subnetId): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.subnet = :id')
            ->setParameter('id', $subnetId)
            ->orderBy('b.startIp', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return AddressBlock[] */
    public function findFixedBySubnet(int $subnetId): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.subnet = :id')
            ->andWhere('b.type = :type')
            ->setParameter('id', $subnetId)
            ->setParameter('type', BlockType::Fixed)
            ->getQuery()
            ->getResult();
    }
}
