<?php

namespace App\Repository;

use App\Entity\Domain;
use App\Entity\InterfaceName;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InterfaceName>
 */
class InterfaceNameRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InterfaceName::class);
    }

    public function searchPaginatedForDomain(Domain $domain, string $q, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('n')
            ->join('n.networkInterface', 'ni')
            ->leftJoin('ni.host', 'h')
            ->leftJoin('ni.ipAddress', 'ip')
            ->leftJoin('ni.ipv6Address', 'ip6')
            ->where('n.domain = :domain')
            ->setParameter('domain', $domain)
            ->orderBy('n.name', 'ASC');

        if ($q !== '') {
            $qb->andWhere('n.name LIKE :q OR h.name LIKE :q OR ip.address LIKE :q OR ip6.address LIKE :q')
               ->setParameter('q', '%' . $q . '%');
        }

        $total = (int) (clone $qb)
            ->select('COUNT(DISTINCT n.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $names = $qb
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['names' => $names, 'total' => $total];
    }
}
