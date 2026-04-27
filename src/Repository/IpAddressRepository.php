<?php

namespace App\Repository;

use App\Entity\IpAddress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IpAddress>
 */
class IpAddressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IpAddress::class);
    }

    /** @return string[] */
    public function findAllocatedAddressesForSubnet(int $subnetId): array
    {
        return array_column(
            $this->createQueryBuilder('i')
                ->select('i.address')
                ->where('i.subnet = :subnet')
                ->setParameter('subnet', $subnetId)
                ->getQuery()
                ->getArrayResult(),
            'address'
        );
    }
}
