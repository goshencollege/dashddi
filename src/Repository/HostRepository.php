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

    /**
     * Finds a non-deleted host whose interface IP matches the given connection target.
     * If the target is a hostname rather than an IP, a DNS lookup is attempted first.
     */
    public function findByConnectionTarget(string $target): ?Host
    {
        $ip = filter_var($target, FILTER_VALIDATE_IP) !== false
            ? $target
            : (($resolved = gethostbyname($target)) !== $target ? $resolved : null);

        if ($ip === null) {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $this->createQueryBuilder('h')
                ->join('h.interfaces', 'i')
                ->join('i.ipv6Address', 'ip6')
                ->where('ip6.address = :ip')
                ->andWhere('h.deletedAt IS NULL')
                ->setParameter('ip', $ip)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        }

        return $this->createQueryBuilder('h')
            ->join('h.interfaces', 'i')
            ->join('i.ipAddress', 'ipa')
            ->where('ipa.address = :ip')
            ->andWhere('h.deletedAt IS NULL')
            ->setParameter('ip', $ip)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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
    public function findAllPaginated(int $page, int $perPage, string $sort = 'name', string $dir = 'asc'): array
    {
        $offset = max(0, ($page - 1) * $perPage);

        $total = (int) $this->createQueryBuilder('h')
            ->select('COUNT(h.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $ids = $this->idsForPage(
            $this->createQueryBuilder('h'),
            $offset,
            $perPage,
            $sort,
            $dir
        );

        return ['hosts' => $this->fetchByIds($ids), 'total' => $total];
    }

    /** @return array{hosts: Host[], total: int} */
    public function searchPaginated(string $query, int $page, int $perPage, string $sort = 'name', string $dir = 'asc'): array
    {
        $key  = 'host_search_' . md5($query . '|' . $page . '|' . $perPage . '|' . $sort . '|' . $dir);
        $data = $this->cache->get($key, function (ItemInterface $item) use ($query, $page, $perPage, $sort, $dir) {
            $item->expiresAfter(60);
            return $this->paginateFilterQuery($this->buildSearchQb($query), $page, $perPage, $sort, $dir);
        });
        return ['hosts' => $this->fetchByIds($data['ids']), 'total' => $data['total']];
    }

    /** @return array{hosts: Host[], total: int} */
    public function advancedSearchPaginated(array $criteria, int $page, int $perPage): array
    {
        $key  = 'host_advsearch_' . md5(serialize($criteria) . '|' . $page . '|' . $perPage);
        $data = $this->cache->get($key, function (ItemInterface $item) use ($criteria, $page, $perPage) {
            $item->expiresAfter(60);
            return $this->paginateFilterQuery($this->buildAdvancedQb($criteria), $page, $perPage);
        });
        return ['hosts' => $this->fetchByIds($data['ids']), 'total' => $data['total']];
    }

    /** @return array{hosts: Host[], total: int} */
    public function structuredSearchPaginated(array $orGroups, int $page, int $perPage, string $sort = 'name', string $dir = 'asc'): array
    {
        $key  = 'host_structured_' . md5(serialize($orGroups) . '|' . $page . '|' . $perPage . '|' . $sort . '|' . $dir);
        $data = $this->cache->get($key, function (ItemInterface $item) use ($orGroups, $page, $perPage, $sort, $dir) {
            $item->expiresAfter(60);
            return $this->paginateFilterQuery($this->buildStructuredQb($orGroups), $page, $perPage, $sort, $dir);
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
            ->leftJoin('h.building', 'b');

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
        if (!empty($criteria['dhcp_mismatch'])) {
            $ids = $this->findDhcpMismatchHostIds();
            if (empty($ids)) {
                $qb->andWhere('1 = 0');
            } else {
                $qb->andWhere('h.id IN (:dhcp_mismatch_ids)')
                   ->setParameter('dhcp_mismatch_ids', $ids);
            }
        }

        return $qb;
    }

    private function buildStructuredQb(array $orGroups): QueryBuilder
    {
        $qb = $this->createQueryBuilder('h');

        if (empty($orGroups)) {
            return $qb;
        }

        $orX = $qb->expr()->orX();
        $n   = 0;

        foreach ($orGroups as $conditions) {
            $andX = $qb->expr()->andX();
            foreach ($conditions as [$field, $value, $negate]) {
                $dql = $this->structuredConditionDql($field, (string) $value, (bool) $negate, $n, $qb);
                if ($dql !== null) {
                    $andX->add($dql);
                }
                $n++;
            }
            if ($andX->count() > 0) {
                $orX->add($andX);
            }
        }

        if ($orX->count() > 0) {
            $qb->andWhere($orX);
        }

        return $qb;
    }

    private function structuredConditionDql(string $field, string $value, bool $negate, int $n, QueryBuilder $qb): ?string
    {
        if ($value === '' && !in_array($field, ['dhcp_mismatch', 'last_dhcp', 'last_auth'], true)) {
            return null;
        }

        $not = $negate ? 'NOT ' : '';

        switch ($field) {
            case 'name':
                [$cmp, $param] = $this->toStructuredLikeOrEq($value);
                $qb->setParameter("sp_$n", $param);
                return "{$not}(h.name $cmp :sp_{$n})";

            case 'room':
                [$cmp, $param] = $this->toStructuredLikeOrEq($value);
                $qb->setParameter("sp_$n", $param);
                return "{$not}(h.room $cmp :sp_{$n})";

            case 'building':
                $qb->setParameter("sp_$n", (int) $value);
                return "{$not}(h.building = :sp_{$n})";

            case 'subnet':
                if ($value === 'none') {
                    return "{$not}EXISTS (SELECT 1 FROM App\Entity\NetworkInterface i{$n} WHERE i{$n}.host = h AND i{$n}.subnet IS NULL)";
                }
                $qb->setParameter("sp_$n", (int) $value);
                return "{$not}EXISTS (SELECT 1 FROM App\Entity\NetworkInterface i{$n} WHERE i{$n}.host = h AND i{$n}.subnet = :sp_{$n})";

            case 'ip':
                [$cmp, $param] = $this->toStructuredLikeOrEq($value);
                $qb->setParameter("sp_$n", $param);
                return <<<DQL
                    {$not}EXISTS (
                        SELECT 1 FROM App\Entity\NetworkInterface i{$n}
                        LEFT JOIN i{$n}.ipAddress ip4{$n}
                        LEFT JOIN i{$n}.ipv6Address ip6{$n}
                        WHERE i{$n}.host = h AND (
                            ip4{$n}.address $cmp :sp_{$n}
                            OR ip6{$n}.address $cmp :sp_{$n}
                            OR EXISTS (
                                SELECT 1 FROM App\Entity\DhcpLease dl{$n}
                                WHERE dl{$n}.macAddress = i{$n}.macAddress AND dl{$n}.ipAddress $cmp :sp_{$n}
                            )
                        )
                    )
                    DQL;

            case 'mac':
                $macParam = $this->normalizeMacLike($value);
                $macCmp = str_contains($macParam, '%') ? 'LIKE' : '=';
                $qb->setParameter("sp_$n", $macParam);
                return "{$not}EXISTS (SELECT 1 FROM App\Entity\NetworkInterface i{$n} WHERE i{$n}.host = h AND i{$n}.macAddress $macCmp :sp_{$n})";

            case 'tag':
                [$cmp, $param] = $this->toStructuredLikeOrEq($value);
                $qb->setParameter("sp_$n", $param);
                return "{$not}EXISTS (SELECT 1 FROM App\Entity\Host h{$n} JOIN h{$n}.tags tg{$n} WHERE h{$n} = h AND tg{$n}.name $cmp :sp_{$n})";

            case 'dns':
                [$cmp, $param] = $this->toStructuredLikeOrEq($value);
                $qb->setParameter("sp_$n", $param);
                return <<<DQL
                    {$not}EXISTS (
                        SELECT 1 FROM App\Entity\NetworkInterface i{$n}
                        LEFT JOIN i{$n}.domainRecords nr{$n}
                        LEFT JOIN nr{$n}.domain nd{$n}
                        WHERE i{$n}.host = h AND (
                            nr{$n}.hostname $cmp :sp_{$n}
                            OR nd{$n}.name $cmp :sp_{$n}
                            OR CONCAT(nr{$n}.hostname, '.', nd{$n}.name) $cmp :sp_{$n}
                        )
                    )
                    DQL;

            case 'dhcp_mismatch':
                $ids = $this->findDhcpMismatchHostIds();
                if (empty($ids)) {
                    return $negate ? null : '1 = 0';
                }
                $qb->setParameter("sp_{$n}_ids", $ids);
                return $negate ? "h.id NOT IN (:sp_{$n}_ids)" : "h.id IN (:sp_{$n}_ids)";

            case 'last_dhcp':
                return $this->dateConditionDql('lastDhcpAt', $value, $negate, $n, $qb);

            case 'last_auth':
                return $this->dateConditionDql('lastAuthAt', $value, $negate, $n, $qb);

            case 'switch_ip':
                [$cmp, $param] = $this->toStructuredLikeOrEq($value);
                $qb->setParameter("sp_$n", $param);
                return "{$not}EXISTS (SELECT 1 FROM App\Entity\NetworkInterface i{$n} WHERE i{$n}.host = h AND i{$n}.switchIp $cmp :sp_{$n})";

            case 'switch_port':
                [$cmp, $param] = $this->toStructuredLikeOrEq($value);
                $qb->setParameter("sp_$n", $param);
                return "{$not}EXISTS (SELECT 1 FROM App\Entity\NetworkInterface i{$n} WHERE i{$n}.host = h AND i{$n}.switchPort $cmp :sp_{$n})";

            case 'deleted':
                if ($value === '1') {
                    return $negate ? 'h.deletedAt IS NULL' : 'h.deletedAt IS NOT NULL';
                }
                return null;
        }

        return null;
    }

    private function dateConditionDql(string $column, string $value, bool $negate, int $n, QueryBuilder $qb): ?string
    {
        if ($value === 'null') {
            if ($negate) {
                return "EXISTS (SELECT 1 FROM App\Entity\NetworkInterface i{$n} WHERE i{$n}.host = h AND i{$n}.{$column} IS NOT NULL)";
            }
            return "NOT EXISTS (SELECT 1 FROM App\Entity\NetworkInterface i{$n} WHERE i{$n}.host = h AND i{$n}.{$column} IS NOT NULL)";
        }

        $not = $negate ? 'NOT ' : '';

        if (str_contains($value, '..')) {
            [$d1, $d2] = explode('..', $value, 2);
            $date1 = \DateTimeImmutable::createFromFormat('Y-m-d', trim($d1));
            $date2 = \DateTimeImmutable::createFromFormat('Y-m-d', trim($d2));
            if (!$date1 || !$date2) {
                return null;
            }
            $qb->setParameter("sp_{$n}_d1", $date1);
            $qb->setParameter("sp_{$n}_d2", $date2->setTime(23, 59, 59));
            return "{$not}EXISTS (SELECT 1 FROM App\Entity\NetworkInterface i{$n} WHERE i{$n}.host = h AND i{$n}.{$column} BETWEEN :sp_{$n}_d1 AND :sp_{$n}_d2)";
        }

        if (str_starts_with($value, '>')) {
            $date = \DateTimeImmutable::createFromFormat('Y-m-d', substr($value, 1));
            if (!$date) {
                return null;
            }
            $qb->setParameter("sp_$n", $date);
            return "{$not}EXISTS (SELECT 1 FROM App\Entity\NetworkInterface i{$n} WHERE i{$n}.host = h AND i{$n}.{$column} > :sp_{$n})";
        }

        if (str_starts_with($value, '<')) {
            $date = \DateTimeImmutable::createFromFormat('Y-m-d', substr($value, 1));
            if (!$date) {
                return null;
            }
            $qb->setParameter("sp_$n", $date);
            return "{$not}EXISTS (SELECT 1 FROM App\Entity\NetworkInterface i{$n} WHERE i{$n}.host = h AND i{$n}.{$column} < :sp_{$n})";
        }

        return null;
    }

    private function findDhcpMismatchHostIds(): array
    {
        $sql = <<<SQL
            SELECT DISTINCT h.id
            FROM network_interface ni
            JOIN host h ON h.id = ni.host_id
            JOIN subnet s ON s.id = ni.subnet_id
            WHERE ni.deleted_at IS NULL
              AND h.deleted_at IS NULL
              AND ni.last_dhcp_ip IS NOT NULL
              AND s.ipv4_cidr IS NOT NULL
              AND NOT (
                  INET_ATON(ni.last_dhcp_ip) BETWEEN
                      INET_ATON(SUBSTRING_INDEX(s.ipv4_cidr, '/', 1))
                      AND (
                          INET_ATON(SUBSTRING_INDEX(s.ipv4_cidr, '/', 1))
                          + POW(2, 32 - CAST(SUBSTRING_INDEX(s.ipv4_cidr, '/', -1) AS UNSIGNED))
                          - 1
                      )
              )
        SQL;

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql);
        return array_column($rows, 'id');
    }

    // -------------------------------------------------------------------------
    // Pagination helpers
    // -------------------------------------------------------------------------

    /**
     * Count + ID-page for any filter QueryBuilder.
     * Returns IDs only — callers call fetchByIds() separately after caching.
     *
     * @return array{ids: int[], total: int}
     */
    private function paginateFilterQuery(QueryBuilder $filterQb, int $page, int $perPage, string $sort = 'name', string $dir = 'asc'): array
    {
        $offset = max(0, ($page - 1) * $perPage);

        $total = (int) (clone $filterQb)
            ->select('COUNT(DISTINCT h.id)')
            ->getQuery()
            ->getSingleScalarResult();

        if ($total === 0) {
            return ['ids' => [], 'total' => 0];
        }

        $ids = $this->idsForPage(
            (clone $filterQb)->distinct(),
            $offset,
            $perPage,
            $sort,
            $dir
        );

        return ['ids' => $ids, 'total' => $total];
    }

    /** Returns a page of host IDs from the given QueryBuilder, ordered by the requested column. */
    private function idsForPage(QueryBuilder $qb, int $offset, int $perPage, string $sort = 'name', string $dir = 'asc'): array
    {
        $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';

        switch ($sort) {
            case 'updated':
                // Include both sort columns in SELECT so DISTINCT stays compatible with ORDER BY.
                $qb->select('h.id as hid', 'h.updatedAt as hupdated', 'h.name as hname')
                   ->orderBy('h.updatedAt', $dir)
                   ->addOrderBy('h.name', 'ASC');
                break;

            case 'ip':
                // Sort by the host's lowest assigned IPv4 (numeric). Hosts without any IP sort last.
                // DQL doesn't support correlated subqueries in COALESCE, so use LEFT JOINs + GROUP BY.
                $nullFallback = $dir === 'ASC' ? '4294967295' : '0';
                $qb->select('h.id as hid', 'h.name as hname')
                   ->addSelect(sprintf(
                       'COALESCE(MIN(INET_ATON(ipa_sort.address)), %s) AS HIDDEN ip_sort',
                       $nullFallback
                   ))
                   ->leftJoin('h.interfaces', 'i_sort', 'WITH', 'i_sort.deletedAt IS NULL')
                   ->leftJoin('i_sort.ipAddress', 'ipa_sort')
                   ->addGroupBy('h.id')
                   ->orderBy('ip_sort', $dir)
                   ->addOrderBy('h.name', 'ASC');
                break;

            default: // 'name'
                $qb->select('h.id as hid', 'h.name as hname')
                   ->orderBy('h.name', $dir);
                break;
        }

        $rows = $qb->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getScalarResult();

        return array_map('intval', array_column($rows, 'hid'));
    }

    /**
     * Fetch full Host entities by ID with all associations the list template needs
     * eagerly loaded to prevent N+1 queries. Order matches the provided $ids array.
     *
     * @return Host[]
     */
    private function fetchByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $hosts = $this->createQueryBuilder('h')
            ->leftJoin('h.interfaces', 'i')->addSelect('i')
            ->leftJoin('h.tags', 'tg')->addSelect('tg')
            ->leftJoin('i.ipAddress', 'ip4')->addSelect('ip4')
            ->leftJoin('i.ipv6Address', 'ip6')->addSelect('ip6')
            ->leftJoin('i.subnet', 's')->addSelect('s')
            ->leftJoin('i.domainRecords', 'n')->addSelect('n')
            ->leftJoin('n.domain', 'nd')->addSelect('nd')
            ->where('h.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        // Reorder to match the sort order established by idsForPage.
        $indexed = [];
        foreach ($hosts as $host) {
            $indexed[$host->getId()] = $host;
        }
        return array_values(array_filter(array_map(fn($id) => $indexed[$id] ?? null, $ids)));
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

    /**
     * Normalise a user-supplied MAC search value to a LIKE pattern that matches
     * the stored aa:bb:cc:dd:ee:ff format.  Delimiters (colons, dashes, dots,
     * spaces) are stripped from each segment; remaining hex digits are re-paired
     * with colons.  A bare 12-hex-digit string produces an exact pattern; any
     * shorter input produces a substring pattern (%...%); explicit * wildcards
     * are preserved and converted to % exactly as toLike() does for other fields.
     */
    private function normalizeMacLike(string $value): string
    {
        $hasStar = str_contains($value, '*');
        $parts   = explode('*', $value);

        $normalized = array_map(static function (string $part): string {
            $hex = preg_replace('/[^0-9a-fA-F]/', '', $part);
            if ($hex === '') {
                return '';
            }
            return implode(':', str_split(strtolower($hex), 2));
        }, $parts);

        if ($hasStar) {
            return implode('%', $normalized);
        }

        // No wildcard: full 12-hex-digit MAC → exact match; partial → substring.
        $totalHex = preg_replace('/[^0-9a-fA-F]/', '', $value);
        if (strlen($totalHex) === 12) {
            return $normalized[0];
        }

        return '%' . $normalized[0] . '%';
    }

    private function toLike(string $value): string
    {
        return str_contains($value, '*')
            ? str_replace('*', '%', $value)
            : '%' . $value . '%';
    }

    /** Returns [$operator, $param] — LIKE+wildcards when '*' present, '='+ exact value otherwise. */
    private function toStructuredLikeOrEq(string $value): array
    {
        if (str_contains($value, '*')) {
            return ['LIKE', str_replace('*', '%', $value)];
        }
        return ['=', $value];
    }
}
