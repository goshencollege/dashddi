<?php

namespace App\Repository;

use App\Entity\Host;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Host>
 */
class HostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Host::class);
    }

    /** @return Host[] */
    public function search(string $query): array
    {
        $q = '%' . $query . '%';

        return $this->createQueryBuilder('h')
            ->leftJoin('h.interfaces', 'i')
            ->leftJoin('i.subnet', 's')
            ->leftJoin('i.ipAddress', 'ip4')
            ->leftJoin('i.ipv6Address', 'ip6')
            ->where('h.name LIKE :q')
            ->orWhere('h.location LIKE :q')
            ->orWhere('s.name LIKE :q')
            ->orWhere('ip4.address LIKE :q')
            ->orWhere('ip6.address LIKE :q')
            ->orWhere('i.macAddress LIKE :q')
            ->setParameter('q', $q)
            ->distinct()
            ->orderBy('h.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
