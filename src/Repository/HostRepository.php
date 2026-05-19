<?php

namespace App\Repository;

use App\Entity\Host;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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

    // -------------------------------------------------------------------------
    // Full-result methods (kept for any non-paginated callers)
    // -------------------------------------------------------------------------

    /** @return Host[] */
    public function search(string $query): array
    {
        return $this->buildSearchQb($query)
            ->distinct()
            ->orderBy('h.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Host[] */
    public function advancedSearch(array $criteria): array
    {
        return $this->buildAdvancedQb($criteria)
            ->distinct()
            ->orderBy('h.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // -------------------------------------------------------------------------
    // Paginated methods
    // -------------------------------------------------------------------------

    /** @return array{hosts: Host[], total: int} */
    public function findAllPaginated(int $page, int $perPage): array
    {
        $offset = max(0, ($page - 1) * $perPage);

        $total = (int) $this->createQueryBuilder('h')
            ->select('COUNT(h.id)')
            ->where('h.deletedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();

        $ids = $this->idsForPage(
            $this->createQueryBuilder('h')->where('h.deletedAt IS NULL'),
            $offset,
            $perPage
        );

        return ['hosts' => $this->fetchByIds($ids), 'total' => $total];
    }

    /** @return array{hosts: Host[], total: int} */
    public function searchPaginated(string $query, int $page, int $perPage): array
    {
        return $this->paginateFilterQuery($this->buildSearchQb($query), $page, $perPage);
    }

    /** @return array{hosts: Host[], total: int} */
    public function advancedSearchPaginated(array $criteria, int $page, int $perPage): array
    {
        return $this->paginateFilterQuery($this->buildAdvancedQb($criteria), $page, $perPage);
    }

    // -------------------------------------------------------------------------
    // Private query builders
    // -------------------------------------------------------------------------

    private function buildSearchQb(string $query): QueryBuilder
    {
        $q  = '%' . $query . '%';
        $qb = $this->createQueryBuilder('h')
            ->leftJoin('h.building', 'b')
            ->leftJoin('h.tags', 'tg')
            ->leftJoin('h.interfaces', 'i')
            ->leftJoin('i.subnet', 's')
            ->leftJoin('i.ipAddress', 'ip4')
            ->leftJoin('i.ipv6Address', 'ip6')
            ->leftJoin('i.names', 'n')
            ->leftJoin('n.domain', 'nd')
            ->leftJoin('App\Entity\DhcpLease', 'dl', 'WITH', 'dl.macAddress = i.macAddress')
            ->where('h.deletedAt IS NULL')
            ->andWhere('h.name LIKE :q')
            ->orWhere('b.name LIKE :q')
            ->orWhere('h.room LIKE :q')
            ->orWhere("CONCAT(COALESCE(b.name, ''), COALESCE(h.room, '')) LIKE :q")
            ->orWhere('s.name LIKE :q')
            ->orWhere('ip4.address LIKE :q')
            ->orWhere('ip6.address LIKE :q')
            ->orWhere('dl.ipAddress LIKE :q')
            ->orWhere('i.macAddress LIKE :q')
            ->orWhere('n.name LIKE :q')
            ->orWhere('nd.name LIKE :q')
            ->orWhere("CONCAT(n.name, '.', nd.name) LIKE :q")
            ->orWhere('tg.name LIKE :q')
            ->setParameter('q', $q);

        $hex = preg_replace('/[^0-9a-fA-F]/', '', $query);
        if (strlen($hex) === 12) {
            $normalized = implode(':', str_split(strtolower($hex), 2));
            $qb->orWhere('i.macAddress = :mac')->setParameter('mac', $normalized);
        }

        return $qb;
    }

    private function buildAdvancedQb(array $criteria): QueryBuilder
    {
        $qb = $this->createQueryBuilder('h')
            ->leftJoin('h.building', 'b')
            ->leftJoin('h.tags', 'tg')
            ->leftJoin('h.interfaces', 'i')
            ->leftJoin('i.ipAddress', 'ip4')
            ->leftJoin('i.ipv6Address', 'ip6')
            ->leftJoin('i.names', 'n')
            ->leftJoin('n.domain', 'nd')
            ->leftJoin('App\Entity\DhcpLease', 'dl', 'WITH', 'dl.macAddress = i.macAddress')
            ->where('h.deletedAt IS NULL');

        if (!empty($criteria['name'])) {
            $qb->andWhere('h.name LIKE :name')
               ->setParameter('name', $this->toLike($criteria['name']));
        }
        if (!empty($criteria['building'])) {
            $qb->andWhere('h.building = :building')
               ->setParameter('building', (int) $criteria['building']);
        }
        if (!empty($criteria['room'])) {
            $qb->andWhere('h.room LIKE :room')
               ->setParameter('room', $this->toLike($criteria['room']));
        }
        if (!empty($criteria['subnet'])) {
            if ($criteria['subnet'] === 'none') {
                $qb->andWhere('i.subnet IS NULL');
            } else {
                $qb->andWhere('i.subnet = :subnet')
                   ->setParameter('subnet', (int) $criteria['subnet']);
            }
        }
        if (!empty($criteria['ip'])) {
            $qb->andWhere($qb->expr()->orX('ip4.address LIKE :ip', 'ip6.address LIKE :ip', 'dl.ipAddress LIKE :ip'))
               ->setParameter('ip', $this->toLike($criteria['ip']));
        }
        if (!empty($criteria['mac'])) {
            $qb->andWhere('i.macAddress LIKE :mac')
               ->setParameter('mac', $this->toLike($criteria['mac']));
        }
        if (!empty($criteria['tag'])) {
            $qb->andWhere('tg.id = :tag')
               ->setParameter('tag', (int) $criteria['tag']);
        }
        if (!empty($criteria['dns'])) {
            $qb->andWhere($qb->expr()->orX(
                'n.name LIKE :dns',
                'nd.name LIKE :dns',
                "CONCAT(n.name, '.', nd.name) LIKE :dns"
            ))->setParameter('dns', $this->toLike($criteria['dns']));
        }

        return $qb;
    }

    // -------------------------------------------------------------------------
    // Pagination helpers
    // -------------------------------------------------------------------------

    /**
     * Count + ID-page + entity fetch for any filter QueryBuilder.
     * Uses a two-step approach (IDs first, then full hydration) so that LIMIT/OFFSET
     * operates on host rows rather than join-multiplied rows.
     *
     * @return array{hosts: Host[], total: int}
     */
    private function paginateFilterQuery(QueryBuilder $filterQb, int $page, int $perPage): array
    {
        $offset = max(0, ($page - 1) * $perPage);

        $total = (int) (clone $filterQb)
            ->select('COUNT(DISTINCT h.id)')
            ->getQuery()
            ->getSingleScalarResult();

        if ($total === 0) {
            return ['hosts' => [], 'total' => 0];
        }

        $ids = $this->idsForPage(
            (clone $filterQb)->distinct(),
            $offset,
            $perPage
        );

        return ['hosts' => $this->fetchByIds($ids), 'total' => $total];
    }

    /** Returns a page of host IDs from the given QueryBuilder. */
    private function idsForPage(QueryBuilder $qb, int $offset, int $perPage): array
    {
        $rows = $qb->select('h.id as hid', 'h.name as hname')
            ->orderBy('h.name', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'hid');
    }

    /**
     * Fetch full Host entities by ID with all associations the list template needs
     * eagerly loaded to prevent N+1 queries.
     *
     * @return Host[]
     */
    private function fetchByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return $this->createQueryBuilder('h')
            ->leftJoin('h.interfaces', 'i')->addSelect('i')
            ->leftJoin('h.tags', 'tg')->addSelect('tg')
            ->leftJoin('i.ipAddress', 'ip4')->addSelect('ip4')
            ->leftJoin('i.ipv6Address', 'ip6')->addSelect('ip6')
            ->leftJoin('i.subnet', 's')->addSelect('s')
            ->leftJoin('i.names', 'n')->addSelect('n')
            ->leftJoin('n.domain', 'nd')->addSelect('nd')
            ->where('h.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('h.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function purgeDeletedBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('h')
            ->delete()
            ->where('h.deletedAt IS NOT NULL')
            ->andWhere('h.deletedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    private function toLike(string $value): string
    {
        return str_contains($value, '*')
            ? str_replace('*', '%', $value)
            : '%' . $value . '%';
    }
}
