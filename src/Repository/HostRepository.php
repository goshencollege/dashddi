<?php

namespace App\Repository;

use App\Entity\Host;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * @extends ServiceEntityRepository<Host>
 */
class HostRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly CacheInterface $cache,
    ) {
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
    public function findDeletedPaginated(int $page, int $perPage): array
    {
        $offset = max(0, ($page - 1) * $perPage);

        $total = (int) $this->createQueryBuilder('h')
            ->select('COUNT(h.id)')
            ->where('h.deletedAt IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();

        $ids = $this->idsForPage(
            $this->createQueryBuilder('h')->where('h.deletedAt IS NOT NULL'),
            $offset,
            $perPage
        );

        return ['hosts' => $this->fetchByIds($ids), 'total' => $total];
    }

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
        $key  = 'host_search_' . md5($query . '|' . $page . '|' . $perPage);
        $data = $this->cache->get($key, function (ItemInterface $item) use ($query, $page, $perPage) {
            $item->expiresAfter(60);
            $result = $this->paginateFilterQuery($this->buildSearchQb($query), $page, $perPage);
            return [
                'ids'   => array_map(fn(Host $h) => $h->getId(), $result['hosts']),
                'total' => $result['total'],
            ];
        });
        return ['hosts' => $this->fetchByIds($data['ids']), 'total' => $data['total']];
    }

    /** @return array{hosts: Host[], total: int} */
    public function advancedSearchPaginated(array $criteria, int $page, int $perPage): array
    {
        $key  = 'host_advsearch_' . md5(serialize($criteria) . '|' . $page . '|' . $perPage);
        $data = $this->cache->get($key, function (ItemInterface $item) use ($criteria, $page, $perPage) {
            $item->expiresAfter(60);
            $result = $this->paginateFilterQuery($this->buildAdvancedQb($criteria), $page, $perPage);
            return [
                'ids'   => array_map(fn(Host $h) => $h->getId(), $result['hosts']),
                'total' => $result['total'],
            ];
        });
        return ['hosts' => $this->fetchByIds($data['ids']), 'total' => $data['total']];
    }

    // -------------------------------------------------------------------------
    // Private query builders
    // -------------------------------------------------------------------------

    private function buildSearchQb(string $query): QueryBuilder
    {
        $q  = '%' . $query . '%';
        $qb = $this->createQueryBuilder('h')
            ->leftJoin('h.building', 'b')
            ->where('h.deletedAt IS NULL');

        // Classify the query so we can skip subqueries that cannot possibly match,
        // making the basic search behave like the targeted advanced search.
        $isIpLike  = (bool) preg_match('/^\d[\d.]*\./', $query);  // "192.168", "10.0.0.1"
        $hasNonHex = (bool) preg_match('/[g-zG-Z]/', $query);     // letters outside hex range

        if ($isIpLike) {
            // Query looks like an IP address — only search address fields, skip all text.
            $or = $qb->expr()->orX(
                <<<'DQL'
                    EXISTS (
                        SELECT 1 FROM App\Entity\NetworkInterface i2
                        LEFT JOIN i2.ipAddress ip4
                        LEFT JOIN i2.ipv6Address ip6
                        WHERE i2.host = h AND (ip4.address LIKE :q OR ip6.address LIKE :q)
                    )
                DQL,
                <<<'DQL'
                    EXISTS (
                        SELECT 1 FROM App\Entity\NetworkInterface i3
                        WHERE i3.host = h AND EXISTS (
                            SELECT 1 FROM App\Entity\DhcpLease dl2
                            WHERE dl2.macAddress = i3.macAddress AND dl2.ipAddress LIKE :q
                        )
                    )
                DQL
            );
        } elseif ($hasNonHex) {
            // Query contains letters outside the hex range — cannot match an IP address or
            // a MAC address, so skip those fields entirely.
            $or = $qb->expr()->orX(
                'h.name LIKE :q',
                'b.name LIKE :q',
                'h.room LIKE :q',
                "CONCAT(COALESCE(b.name, ''), COALESCE(h.room, '')) LIKE :q",
                <<<'DQL'
                    EXISTS (
                        SELECT 1 FROM App\Entity\NetworkInterface i2
                        LEFT JOIN i2.subnet s2
                        LEFT JOIN i2.domainRecords n2
                        LEFT JOIN n2.domain nd2
                        WHERE i2.host = h AND (
                            s2.name LIKE :q
                            OR n2.hostname LIKE :q
                            OR nd2.name LIKE :q
                            OR CONCAT(n2.hostname, '.', nd2.name) LIKE :q
                        )
                    )
                DQL,
                <<<'DQL'
                    EXISTS (
                        SELECT 1 FROM App\Entity\Host h2
                        JOIN h2.tags tg2
                        WHERE h2 = h AND tg2.name LIKE :q
                    )
                DQL
            );
        } else {
            // Ambiguous (pure digits, hex chars, partial MAC) — run everything to be safe.
            $or = $qb->expr()->orX(
                'h.name LIKE :q',
                'b.name LIKE :q',
                'h.room LIKE :q',
                "CONCAT(COALESCE(b.name, ''), COALESCE(h.room, '')) LIKE :q",
                <<<'DQL'
                    EXISTS (
                        SELECT 1 FROM App\Entity\NetworkInterface i2
                        LEFT JOIN i2.subnet s2
                        LEFT JOIN i2.ipAddress ip4
                        LEFT JOIN i2.ipv6Address ip6
                        LEFT JOIN i2.domainRecords n2
                        LEFT JOIN n2.domain nd2
                        WHERE i2.host = h AND (
                            i2.macAddress LIKE :q
                            OR s2.name LIKE :q
                            OR ip4.address LIKE :q
                            OR ip6.address LIKE :q
                            OR n2.hostname LIKE :q
                            OR nd2.name LIKE :q
                            OR CONCAT(n2.hostname, '.', nd2.name) LIKE :q
                        )
                    )
                DQL,
                <<<'DQL'
                    EXISTS (
                        SELECT 1 FROM App\Entity\NetworkInterface i3
                        WHERE i3.host = h AND EXISTS (
                            SELECT 1 FROM App\Entity\DhcpLease dl2
                            WHERE dl2.macAddress = i3.macAddress AND dl2.ipAddress LIKE :q
                        )
                    )
                DQL,
                <<<'DQL'
                    EXISTS (
                        SELECT 1 FROM App\Entity\Host h2
                        JOIN h2.tags tg2
                        WHERE h2 = h AND tg2.name LIKE :q
                    )
                DQL
            );
        }

        $qb->andWhere($or)->setParameter('q', $q);

        $hex = preg_replace('/[^0-9a-fA-F]/', '', $query);
        if (strlen($hex) === 12) {
            $normalized = implode(':', str_split(strtolower($hex), 2));
            $qb->orWhere(<<<'DQL'
                    EXISTS (
                        SELECT 1 FROM App\Entity\NetworkInterface i4
                        WHERE i4.host = h AND i4.macAddress = :mac
                    )
                DQL)
               ->setParameter('mac', $normalized);
        }

        return $qb;
    }

    private function buildAdvancedQb(array $criteria): QueryBuilder
    {
        $qb = $this->createQueryBuilder('h')
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
                $qb->andWhere('EXISTS (SELECT 1 FROM App\Entity\NetworkInterface i2 WHERE i2.host = h AND i2.subnet IS NULL)');
            } else {
                $qb->andWhere('EXISTS (SELECT 1 FROM App\Entity\NetworkInterface i2 WHERE i2.host = h AND i2.subnet = :subnet)')
                   ->setParameter('subnet', (int) $criteria['subnet']);
            }
        }
        if (!empty($criteria['ip'])) {
            $qb->andWhere(<<<'DQL'
                    EXISTS (
                        SELECT 1 FROM App\Entity\NetworkInterface i3
                        LEFT JOIN i3.ipAddress ip4
                        LEFT JOIN i3.ipv6Address ip6
                        WHERE i3.host = h AND (
                            ip4.address LIKE :ip
                            OR ip6.address LIKE :ip
                            OR EXISTS (
                                SELECT 1 FROM App\Entity\DhcpLease dl2
                                WHERE dl2.macAddress = i3.macAddress AND dl2.ipAddress LIKE :ip
                            )
                        )
                    )
                DQL)
               ->setParameter('ip', $this->toLike($criteria['ip']));
        }
        if (!empty($criteria['mac'])) {
            $qb->andWhere('EXISTS (SELECT 1 FROM App\Entity\NetworkInterface i4 WHERE i4.host = h AND i4.macAddress LIKE :mac)')
               ->setParameter('mac', $this->toLike($criteria['mac']));
        }
        if (!empty($criteria['tag'])) {
            $qb->andWhere('EXISTS (SELECT 1 FROM App\Entity\Host h2 JOIN h2.tags tg2 WHERE h2 = h AND tg2.id = :tag)')
               ->setParameter('tag', (int) $criteria['tag']);
        }
        if (!empty($criteria['dns'])) {
            $qb->andWhere(<<<'DQL'
                    EXISTS (
                        SELECT 1 FROM App\Entity\NetworkInterface i5
                        LEFT JOIN i5.domainRecords n2
                        LEFT JOIN n2.domain nd2
                        WHERE i5.host = h AND (
                            n2.hostname LIKE :dns
                            OR nd2.name LIKE :dns
                            OR CONCAT(n2.hostname, '.', nd2.name) LIKE :dns
                        )
                    )
                DQL)
               ->setParameter('dns', $this->toLike($criteria['dns']));
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
            ->leftJoin('i.domainRecords', 'n')->addSelect('n')
            ->leftJoin('n.domain', 'nd')->addSelect('nd')
            ->where('h.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('h.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function purgeDeletedBefore(\DateTimeImmutable $before): int
    {
        $entities = $this->createQueryBuilder('h')
            ->where('h.deletedAt IS NOT NULL')
            ->andWhere('h.deletedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->getResult();

        foreach ($entities as $entity) {
            $this->getEntityManager()->remove($entity);
        }
        if (!empty($entities)) {
            $this->getEntityManager()->flush();
        }

        return count($entities);
    }

    private function toLike(string $value): string
    {
        return str_contains($value, '*')
            ? str_replace('*', '%', $value)
            : '%' . $value . '%';
    }
}
