<?php

namespace App\Repository;

use App\Entity\Ipv6Address;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ipv6Address>
 */
class Ipv6AddressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ipv6Address::class);
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
