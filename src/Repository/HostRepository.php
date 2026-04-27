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
        $q  = '%' . $query . '%';
        $qb = $this->createQueryBuilder('h')
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
            ->setParameter('q', $q);

        // If the query is (or contains) a MAC in any delimiter/case style,
        // also match the normalized colon-separated form stored in the DB.
        $hex = preg_replace('/[^0-9a-fA-F]/', '', $query);
        if (strlen($hex) === 12) {
            $normalized = implode(':', str_split(strtolower($hex), 2));
            $qb->orWhere('i.macAddress = :mac')->setParameter('mac', $normalized);
        }

        return $qb
            ->distinct()
            ->orderBy('h.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
