<?php

namespace App\Repository;

use App\Entity\DeniedMacAddress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DeniedMacAddress> */
class DeniedMacAddressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeniedMacAddress::class);
    }

    /** @return string[] MAC addresses of currently-enabled deny entries */
    public function findAllMacAddresses(): array
    {
        return array_column(
            $this->createQueryBuilder('d')
                ->select('d.macAddress')
                ->andWhere('d.enabled = true')
                ->getQuery()
                ->getArrayResult(),
            'macAddress'
        );
    }
}
