<?php

namespace App\Repository;

use App\Entity\Domain;
use App\Entity\DomainRecord;
use App\Entity\NetworkInterface;
use App\Entity\VirtualIp;
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

    public function advancedSearchPaginated(Domain $domain, array $criteria, int $page, int $perPage): array
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

        if (!empty($criteria['hostname'])) {
            $pattern = str_replace('*', '%', $criteria['hostname']);
            if (!str_contains($pattern, '%')) {
                $pattern = '%' . $pattern . '%';
            }
            $qb->andWhere('r.hostname LIKE :hostname')->setParameter('hostname', $pattern);
        }

        if (!empty($criteria['type'])) {
            $qb->andWhere('r.type = :type')->setParameter('type', $criteria['type']);
        }

        if (!empty($criteria['value'])) {
            $pattern = str_replace('*', '%', $criteria['value']);
            if (!str_contains($pattern, '%')) {
                $pattern = '%' . $pattern . '%';
            }
            $qb->andWhere(
                'r.value LIKE :value OR ip.address LIKE :value OR ip6.address LIKE :value'
            )->setParameter('value', $pattern);
        }

        if (!empty($criteria['host'])) {
            $pattern = str_replace('*', '%', $criteria['host']);
            if (!str_contains($pattern, '%')) {
                $pattern = '%' . $pattern . '%';
            }
            $qb->andWhere('h.name LIKE :host')->setParameter('host', $pattern);
        }

        if (!empty($criteria['view'])) {
            $qb->join('r.views', 'v')->andWhere('v.id = :view')->setParameter('view', (int) $criteria['view']);
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

    public function hasAnyForVirtualIp(VirtualIp $vip, RecordType $type): bool
    {
        $count = (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.virtualIp = :vip')
            ->andWhere('r.type = :type')
            ->setParameter('vip', $vip)
            ->setParameter('type', $type)
            ->getQuery()
            ->getSingleScalarResult();
        return $count > 0;
    }

    public function hasOtherRecordsForHostname(Domain $domain, string $hostname, ?int $excludeId, array $viewIds = []): bool
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(DISTINCT r.id)')
            ->where('r.domain = :domain')
            ->andWhere('r.hostname = :hostname')
            ->setParameter('domain', $domain)
            ->setParameter('hostname', $hostname);

        if ($excludeId !== null) {
            $qb->andWhere('r.id != :id')->setParameter('id', $excludeId);
        }

        $this->applyViewFilter($qb, $viewIds);

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function hasCnameForHostname(Domain $domain, string $hostname, ?int $excludeId, array $viewIds = []): bool
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(DISTINCT r.id)')
            ->where('r.domain = :domain')
            ->andWhere('r.hostname = :hostname')
            ->andWhere('r.type = :type')
            ->setParameter('domain', $domain)
            ->setParameter('hostname', $hostname)
            ->setParameter('type', RecordType::CNAME);

        if ($excludeId !== null) {
            $qb->andWhere('r.id != :id')->setParameter('id', $excludeId);
        }

        $this->applyViewFilter($qb, $viewIds);

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function hasOtherSpfTxtForHostname(Domain $domain, string $hostname, ?int $excludeId, array $viewIds = []): bool
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(DISTINCT r.id)')
            ->where('r.domain = :domain')
            ->andWhere('r.hostname = :hostname')
            ->andWhere('r.type = :type')
            ->andWhere('r.value LIKE :prefix')
            ->setParameter('domain', $domain)
            ->setParameter('hostname', $hostname)
            ->setParameter('type', RecordType::TXT)
            ->setParameter('prefix', 'v=spf1%');

        if ($excludeId !== null) {
            $qb->andWhere('r.id != :id')->setParameter('id', $excludeId);
        }

        $this->applyViewFilter($qb, $viewIds);

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Restricts a query to records that share at least one view with the candidate.
     * Records with no views only conflict with other records that also have no views.
     */
    private function applyViewFilter(\Doctrine\ORM\QueryBuilder $qb, array $viewIds): void
    {
        if (!empty($viewIds)) {
            $qb->join('r.views', 'v')
                ->andWhere('v.id IN (:viewIds)')
                ->setParameter('viewIds', $viewIds);
        } else {
            $qb->leftJoin('r.views', 'v')
                ->andWhere('v.id IS NULL');
        }
    }
}
