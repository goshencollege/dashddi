<?php

namespace App\Repository;

use App\Entity\Domain;
use App\Entity\DomainRecord;
use App\Entity\NetworkInterface;
use App\Enum\RecordType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DomainRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DomainRecord::class);
    }

    public function searchPaginated(Domain $domain, string $q, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.networkInterface', 'ni')
            ->leftJoin('ni.host', 'h')
            ->leftJoin('ni.ipAddress', 'ip')
            ->leftJoin('ni.ipv6Address', 'ip6')
            ->where('r.domain = :domain')
            ->setParameter('domain', $domain)
            ->orderBy('r.hostname', 'ASC')
            ->addOrderBy('r.type', 'ASC');

        if ($q !== '') {
            $qb->andWhere(
                'r.hostname LIKE :q OR r.value LIKE :q OR h.name LIKE :q OR ip.address LIKE :q OR ip6.address LIKE :q'
            )->setParameter('q', '%' . $q . '%');
        }

        $total = (int) (clone $qb)
            ->select('COUNT(DISTINCT r.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $records = $qb
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['records' => $records, 'total' => $total];
    }

    public function searchPaginatedForInterface(NetworkInterface $iface, string $q, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.networkInterface = :iface')
            ->setParameter('iface', $iface)
            ->orderBy('r.hostname', 'ASC')
            ->addOrderBy('r.type', 'ASC');

        if ($q !== '') {
            $qb->andWhere('r.hostname LIKE :q OR r.value LIKE :q')
               ->setParameter('q', '%' . $q . '%');
        }

        $total = (int) (clone $qb)
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $records = $qb
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['records' => $records, 'total' => $total];
    }

    public function countCanonicalForInterface(NetworkInterface $iface, RecordType $type): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.networkInterface = :iface')
            ->andWhere('r.type = :type')
            ->andWhere('r.isCanonical = :canonical')
            ->setParameter('iface', $iface)
            ->setParameter('type', $type)
            ->setParameter('canonical', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function hasAnyForInterface(NetworkInterface $iface, RecordType $type): bool
    {
        $count = (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.networkInterface = :iface')
            ->andWhere('r.type = :type')
            ->setParameter('iface', $iface)
            ->setParameter('type', $type)
            ->getQuery()
            ->getSingleScalarResult();
        return $count > 0;
    }
}
