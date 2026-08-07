<?php

namespace App\Service;

use App\Entity\Domain;
use App\Entity\DomainRecord;
use App\Entity\NetworkInterface;
use App\Entity\Subnet;
use App\Entity\VirtualIp;
use App\Enum\RecordType;
use Doctrine\ORM\EntityManagerInterface;

class RecommendationService
{
    public const DHCP_EXCLUSION_TAG = 'dhcp-ok';
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DnsViewResolver $viewResolver,
    ) {}

    private ?array $reparentCandidatesCache = null;

    /**
     * Finds A/AAAA domain records whose IP value matches a network interface or VIP
     * but are not linked to that entity.
     *
     * Each row contains: record_id, hostname, ip, domain_id, domain_name,
     * record_type, match_type ('interface'|'vip'), match_id, match_label, match_sublabel.
     */
    public function findUnlinkedDnsRecords(): array
    {
        $sql = <<<SQL
            SELECT
                dr.id        AS record_id,
                dr.hostname,
                dr.value     AS ip,
                d.id         AS domain_id,
                d.name       AS domain_name,
                'A'          AS record_type,
                'interface'  AS match_type,
                ni.id        AS match_id,
                h.name       AS match_label,
                ni.name      AS match_sublabel
            FROM domain_record dr
            JOIN domain d              ON dr.domain_id       = d.id
            JOIN ip_address ia         ON dr.value           = ia.address
            JOIN network_interface ni  ON ni.ip_address_id   = ia.id
            JOIN host h                ON ni.host_id         = h.id
            WHERE dr.type = 'A'
              AND dr.network_interface_id IS NULL
              AND dr.virtual_ip_id IS NULL
              AND ni.deleted_at IS NULL

            UNION ALL

            SELECT
                dr.id        AS record_id,
                dr.hostname,
                dr.value     AS ip,
                d.id         AS domain_id,
                d.name       AS domain_name,
                'AAAA'       AS record_type,
                'interface'  AS match_type,
                ni.id        AS match_id,
                h.name       AS match_label,
                ni.name      AS match_sublabel
            FROM domain_record dr
            JOIN domain d              ON dr.domain_id        = d.id
            JOIN ipv6_address ia6      ON dr.value            = ia6.address
            JOIN network_interface ni  ON ni.ipv6_address_id  = ia6.id
            JOIN host h                ON ni.host_id          = h.id
            WHERE dr.type = 'AAAA'
              AND dr.network_interface_id IS NULL
              AND dr.virtual_ip_id IS NULL
              AND ni.deleted_at IS NULL

            UNION ALL

            SELECT
                dr.id        AS record_id,
                dr.hostname,
                dr.value     AS ip,
                d.id         AS domain_id,
                d.name       AS domain_name,
                'A'          AS record_type,
                'vip'        AS match_type,
                vip.id       AS match_id,
                vip.label    AS match_label,
                NULL         AS match_sublabel
            FROM domain_record dr
            JOIN domain d         ON dr.domain_id       = d.id
            JOIN ip_address ia    ON dr.value           = ia.address
            JOIN virtual_ip vip   ON vip.ip_address_id  = ia.id
            WHERE dr.type = 'A'
              AND dr.network_interface_id IS NULL
              AND dr.virtual_ip_id IS NULL
              AND vip.deleted_at IS NULL

            UNION ALL

            SELECT
                dr.id        AS record_id,
                dr.hostname,
                dr.value     AS ip,
                d.id         AS domain_id,
                d.name       AS domain_name,
                'AAAA'       AS record_type,
                'vip'        AS match_type,
                vip.id       AS match_id,
                vip.label    AS match_label,
                NULL         AS match_sublabel
            FROM domain_record dr
            JOIN domain d          ON dr.domain_id        = d.id
            JOIN ipv6_address ia6  ON dr.value            = ia6.address
            JOIN virtual_ip vip    ON vip.ipv6_address_id = ia6.id
            WHERE dr.type = 'AAAA'
              AND dr.network_interface_id IS NULL
              AND dr.virtual_ip_id IS NULL
              AND vip.deleted_at IS NULL

            ORDER BY domain_name, hostname
        SQL;

        return $this->em->getConnection()->fetchAllAssociative($sql);
    }

    public function findInterfaceForDnsRecord(DomainRecord $record): ?NetworkInterface
    {
        if ($record->getNetworkInterface() !== null || $record->getVirtualIp() !== null) {
            return null;
        }

        $value = $record->getValue();

        if ($record->getType() === RecordType::A) {
            return $this->em->createQueryBuilder()
                ->select('ni')
                ->from(NetworkInterface::class, 'ni')
                ->join('ni.ipAddress', 'ip')
                ->where('ip.address = :value')
                ->andWhere('ni.deletedAt IS NULL')
                ->setParameter('value', $value)
                ->getQuery()
                ->getOneOrNullResult();
        }

        if ($record->getType() === RecordType::AAAA) {
            return $this->em->createQueryBuilder()
                ->select('ni')
                ->from(NetworkInterface::class, 'ni')
                ->join('ni.ipv6Address', 'ip6')
                ->where('ip6.address = :value')
                ->andWhere('ni.deletedAt IS NULL')
                ->setParameter('value', $value)
                ->getQuery()
                ->getOneOrNullResult();
        }

        return null;
    }

    public function findVipForDnsRecord(DomainRecord $record): ?VirtualIp
    {
        if ($record->getNetworkInterface() !== null || $record->getVirtualIp() !== null) {
            return null;
        }

        $value = $record->getValue();

        if ($record->getType() === RecordType::A) {
            return $this->em->createQueryBuilder()
                ->select('vip')
                ->from(VirtualIp::class, 'vip')
                ->join('vip.ipAddress', 'ip')
                ->where('ip.address = :value')
                ->andWhere('vip.deletedAt IS NULL')
                ->setParameter('value', $value)
                ->getQuery()
                ->getOneOrNullResult();
        }

        if ($record->getType() === RecordType::AAAA) {
            return $this->em->createQueryBuilder()
                ->select('vip')
                ->from(VirtualIp::class, 'vip')
                ->join('vip.ipv6Address', 'ip6')
                ->where('ip6.address = :value')
                ->andWhere('vip.deletedAt IS NULL')
                ->setParameter('value', $value)
                ->getQuery()
                ->getOneOrNullResult();
        }

        return null;
    }

    /**
     * Finds unlinked CNAME records that point to linked A/AAAA records in the system.
     *
     * Each entry has: record_id, hostname, cname_target, domain_id, domain_name,
     * conversions[] — one item per matching linked A/AAAA record, each with:
     *   target_type, match_type ('interface'|'vip'), match_id, match_label, match_sublabel.
     *
     * Converting replaces the CNAME with one A/AAAA record per conversion target,
     * linked to the corresponding interface or VIP.
     */
    public function findConvertibleCnameRecords(): array
    {
        return $this->groupCnameRows($this->fetchCnameTargetRows());
    }

    /**
     * Returns all conversion targets for a single CNAME, or [] if none found.
     */
    public function findCnameConversionTargets(int $cnameId): array
    {
        return array_map(
            fn($row) => $this->rowToTarget($row),
            $this->fetchCnameTargetRows($cnameId),
        );
    }

    /**
     * Finds CNAME records that point to a hostname within the same zone but that
     * hostname does not exist as any record in that domain.
     *
     * Uses the same three-form matching as the CNAME conversion check to decide
     * whether a value refers to a record in the same zone:
     *   1. value = hostname          (bare/dotted label, relative to zone)
     *   2. value = hostname.domain   (FQDN without trailing dot)
     *   3. value = hostname.domain.  (FQDN with trailing dot)
     * A CNAME is orphaned when none of those forms matches any existing record
     * in the domain. CNAMEs pointing to the naked domain (zone apex) are excluded.
     *
     * Each row contains: record_id, hostname, cname_target, domain_id, domain_name.
     */
    public function findOrphanedCnameRecords(): array
    {
        $sql = <<<SQL
            SELECT
                dr.id       AS record_id,
                dr.hostname,
                dr.value    AS cname_target,
                d.id        AS domain_id,
                d.name      AS domain_name
            FROM domain_record dr
            JOIN domain d ON dr.domain_id = d.id
            WHERE dr.type = 'CNAME'
              AND dr.value != d.name
              AND dr.value != CONCAT(d.name, '.')
              AND NOT (dr.value LIKE '%.' AND dr.value NOT LIKE CONCAT('%.', d.name, '.'))
              AND NOT EXISTS (
                  SELECT 1 FROM domain_record dr2
                  WHERE dr2.domain_id = dr.domain_id
                    AND (
                        dr.value = dr2.hostname
                        OR dr.value = CONCAT(dr2.hostname, '.', d.name)
                        OR dr.value = CONCAT(dr2.hostname, '.', d.name, '.')
                    )
              )
            ORDER BY d.name, dr.hostname
        SQL;

        return $this->em->getConnection()->fetchAllAssociative($sql);
    }

    /**
     * Finds dual-stack interfaces and VIPs that have an A or AAAA record linked
     * but are missing the complementary record for the same hostname and domain.
     *
     * Each row contains: existing_record_id, hostname, domain_id, domain_name,
     * missing_type ('A'|'AAAA'), match_type ('interface'|'vip'), match_id,
     * match_label, match_sublabel.
     */
    public function findMissingDualStackRecords(): array
    {
        $sql = <<<SQL
            SELECT
                'AAAA'       AS missing_type,
                'interface'  AS match_type,
                ni.id        AS match_id,
                h.name       AS match_label,
                ni.name      AS match_sublabel,
                dr.id        AS existing_record_id,
                dr.hostname,
                d.id         AS domain_id,
                d.name       AS domain_name
            FROM network_interface ni
            JOIN host h           ON h.id    = ni.host_id
            JOIN ip_address ia    ON ia.id   = ni.ip_address_id
            JOIN ipv6_address ia6 ON ia6.id  = ni.ipv6_address_id
            JOIN domain_record dr ON dr.network_interface_id = ni.id AND dr.type = 'A'
            JOIN domain d         ON d.id    = dr.domain_id
            WHERE ni.deleted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM domain_record dr2
                  WHERE dr2.network_interface_id = ni.id
                    AND dr2.type = 'AAAA'
                    AND dr2.hostname  = dr.hostname
                    AND dr2.domain_id = dr.domain_id
              )

            UNION ALL

            SELECT
                'A'          AS missing_type,
                'interface'  AS match_type,
                ni.id        AS match_id,
                h.name       AS match_label,
                ni.name      AS match_sublabel,
                dr.id        AS existing_record_id,
                dr.hostname,
                d.id         AS domain_id,
                d.name       AS domain_name
            FROM network_interface ni
            JOIN host h           ON h.id    = ni.host_id
            JOIN ip_address ia    ON ia.id   = ni.ip_address_id
            JOIN ipv6_address ia6 ON ia6.id  = ni.ipv6_address_id
            JOIN domain_record dr ON dr.network_interface_id = ni.id AND dr.type = 'AAAA'
            JOIN domain d         ON d.id    = dr.domain_id
            WHERE ni.deleted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM domain_record dr2
                  WHERE dr2.network_interface_id = ni.id
                    AND dr2.type = 'A'
                    AND dr2.hostname  = dr.hostname
                    AND dr2.domain_id = dr.domain_id
              )

            UNION ALL

            SELECT
                'AAAA'       AS missing_type,
                'vip'        AS match_type,
                vip.id       AS match_id,
                vip.label    AS match_label,
                NULL         AS match_sublabel,
                dr.id        AS existing_record_id,
                dr.hostname,
                d.id         AS domain_id,
                d.name       AS domain_name
            FROM virtual_ip vip
            JOIN ip_address ia    ON ia.id   = vip.ip_address_id
            JOIN ipv6_address ia6 ON ia6.id  = vip.ipv6_address_id
            JOIN domain_record dr ON dr.virtual_ip_id = vip.id AND dr.type = 'A'
            JOIN domain d         ON d.id    = dr.domain_id
            WHERE vip.deleted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM domain_record dr2
                  WHERE dr2.virtual_ip_id = vip.id
                    AND dr2.type = 'AAAA'
                    AND dr2.hostname  = dr.hostname
                    AND dr2.domain_id = dr.domain_id
              )

            UNION ALL

            SELECT
                'A'          AS missing_type,
                'vip'        AS match_type,
                vip.id       AS match_id,
                vip.label    AS match_label,
                NULL         AS match_sublabel,
                dr.id        AS existing_record_id,
                dr.hostname,
                d.id         AS domain_id,
                d.name       AS domain_name
            FROM virtual_ip vip
            JOIN ip_address ia    ON ia.id   = vip.ip_address_id
            JOIN ipv6_address ia6 ON ia6.id  = vip.ipv6_address_id
            JOIN domain_record dr ON dr.virtual_ip_id = vip.id AND dr.type = 'AAAA'
            JOIN domain d         ON d.id    = dr.domain_id
            WHERE vip.deleted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM domain_record dr2
                  WHERE dr2.virtual_ip_id = vip.id
                    AND dr2.type = 'A'
                    AND dr2.hostname  = dr.hostname
                    AND dr2.domain_id = dr.domain_id
              )

            ORDER BY match_label, hostname, domain_name
        SQL;

        return $this->em->getConnection()->fetchAllAssociative($sql);
    }

    /**
     * Finds DNS records that have no views assigned but whose domain (and subnet, if
     * applicable) has views configured. Returns only records where there is at least
     * one view that should be added.
     *
     * Each row contains: record_id, hostname, record_type, domain_id, domain_name,
     * match_type ('interface'|'vip'|null), match_id, match_label, match_sublabel,
     * views (array of {id, name}).
     */
    public function findRecordsWithMissingViews(): array
    {
        $sql = <<<SQL
            SELECT
                dr.id           AS record_id,
                dr.hostname,
                dr.type         AS record_type,
                d.id            AS domain_id,
                d.name          AS domain_name,
                ni.id           AS iface_id,
                h.name          AS host_name,
                ni.name         AS iface_name,
                vip.id          AS vip_id,
                vip.label       AS vip_label,
                COALESCE(ni.subnet_id, vip.subnet_id) AS subnet_id
            FROM domain_record dr
            JOIN domain d ON dr.domain_id = d.id
            LEFT JOIN network_interface ni  ON ni.id  = dr.network_interface_id AND ni.deleted_at  IS NULL
            LEFT JOIN host h                ON h.id   = ni.host_id
            LEFT JOIN virtual_ip vip        ON vip.id = dr.virtual_ip_id        AND vip.deleted_at IS NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM domain_record_dns_view drdv
                WHERE drdv.domain_record_id = dr.id
            )
            AND EXISTS (
                SELECT 1 FROM domain_dns_view ddv
                WHERE ddv.domain_id = d.id
            )
            ORDER BY d.name, dr.hostname
        SQL;

        $rows    = $this->em->getConnection()->fetchAllAssociative($sql);
        $results = [];

        foreach ($rows as $row) {
            $domain = $this->em->find(Domain::class, (int) $row['domain_id']);
            $subnet = $row['subnet_id'] !== null
                ? $this->em->find(Subnet::class, (int) $row['subnet_id'])
                : null;

            $views = $this->viewResolver->availableViewsFor($domain, $subnet);
            if (empty($views)) {
                continue;
            }

            $results[] = [
                'record_id'      => (int) $row['record_id'],
                'hostname'       => $row['hostname'],
                'record_type'    => $row['record_type'],
                'domain_id'      => (int) $row['domain_id'],
                'domain_name'    => $row['domain_name'],
                'match_type'     => $row['iface_id'] !== null ? 'interface' : ($row['vip_id'] !== null ? 'vip' : null),
                'match_id'       => $row['iface_id'] ?? $row['vip_id'],
                'match_label'    => $row['host_name'] ?? $row['vip_label'],
                'match_sublabel' => $row['iface_name'],
                'views'          => array_map(
                    fn($v) => ['id' => $v->getId(), 'name' => $v->getName()],
                    $views,
                ),
            ];
        }

        return $results;
    }

    /**
     * Finds network interfaces whose most recent DHCP lease was issued by a subnet
     * other than the one the interface is assigned to.
     *
     * Hosts tagged with DHCP_EXCLUSION_TAG are excluded. Each row contains:
     * interface_id, interface_name, mac_address, host_id, host_name,
     * assigned_subnet_id, assigned_subnet_name, assigned_cidr,
     * lease_subnet_id, lease_subnet_name, lease_cidr, lease_ip, lease_start.
     */
    public function findDhcpSubnetMismatches(): array
    {
        $sql = <<<SQL
            SELECT
                ni.id            AS interface_id,
                ni.name          AS interface_name,
                ni.mac_address,
                ni.last_dhcp_ip  AS lease_ip,
                ni.last_dhcp_at  AS lease_start,
                h.id             AS host_id,
                h.name           AS host_name,
                sa.id            AS assigned_subnet_id,
                sa.name          AS assigned_subnet_name,
                sa.ipv4_cidr     AS assigned_cidr,
                sl.id            AS lease_subnet_id,
                sl.name          AS lease_subnet_name,
                sl.ipv4_cidr     AS lease_cidr
            FROM network_interface ni
            JOIN host h    ON h.id  = ni.host_id
            JOIN subnet sa ON sa.id = ni.subnet_id
            LEFT JOIN subnet sl ON sl.id = (
                SELECT s2.id FROM subnet s2
                WHERE s2.ipv4_cidr IS NOT NULL
                  AND INET_ATON(ni.last_dhcp_ip) BETWEEN
                      INET_ATON(SUBSTRING_INDEX(s2.ipv4_cidr, '/', 1))
                      AND INET_ATON(SUBSTRING_INDEX(s2.ipv4_cidr, '/', 1))
                         + POW(2, 32 - CAST(SUBSTRING_INDEX(s2.ipv4_cidr, '/', -1) AS UNSIGNED)) - 1
                ORDER BY CAST(SUBSTRING_INDEX(s2.ipv4_cidr, '/', -1) AS UNSIGNED) DESC
                LIMIT 1
            )
            WHERE ni.deleted_at IS NULL
              AND h.deleted_at  IS NULL
              AND ni.last_dhcp_ip IS NOT NULL
              AND sa.ipv4_cidr IS NOT NULL
              AND NOT (
                  INET_ATON(ni.last_dhcp_ip) BETWEEN
                      INET_ATON(SUBSTRING_INDEX(sa.ipv4_cidr, '/', 1))
                      AND INET_ATON(SUBSTRING_INDEX(sa.ipv4_cidr, '/', 1))
                         + POW(2, 32 - CAST(SUBSTRING_INDEX(sa.ipv4_cidr, '/', -1) AS UNSIGNED)) - 1
              )
              AND h.id NOT IN (
                  SELECT ht.host_id
                  FROM host_tag ht
                  JOIN tag t ON t.id = ht.tag_id
                  WHERE t.name = :exclusion_tag
              )
            ORDER BY h.name, ni.name
        SQL;

        return $this->em->getConnection()->fetchAllAssociative($sql, [
            'exclusion_tag' => self::DHCP_EXCLUSION_TAG,
        ]);
    }

    /**
     * Returns the Subnet whose CIDR contains the interface's last_dhcp_ip,
     * or null if the IP is unset or does not fall within any known subnet.
     */
    public function findSubnetForLastDhcpIp(NetworkInterface $interface): ?Subnet
    {
        $ip = $interface->getLastDhcpIp();
        if ($ip === null) {
            return null;
        }

        $sql = <<<SQL
            SELECT id FROM subnet
            WHERE ipv4_cidr IS NOT NULL
              AND INET_ATON(:ip) BETWEEN
                  INET_ATON(SUBSTRING_INDEX(ipv4_cidr, '/', 1))
                  AND INET_ATON(SUBSTRING_INDEX(ipv4_cidr, '/', 1))
                     + POW(2, 32 - CAST(SUBSTRING_INDEX(ipv4_cidr, '/', -1) AS UNSIGNED)) - 1
            ORDER BY CAST(SUBSTRING_INDEX(ipv4_cidr, '/', -1) AS UNSIGNED) DESC
            LIMIT 1
        SQL;

        $id = $this->em->getConnection()->fetchOne($sql, ['ip' => $ip]);
        return $id !== false ? $this->em->find(Subnet::class, (int) $id) : null;
    }

    /**
     * Finds non-expired API tokens that have not been used in the past 6 months
     * (or were never used and are at least 6 months old).
     *
     * Each row contains: id, name, owner_identifier, last_used_at, created_at, expires_at.
     */
    public function findStaleApiTokens(): array
    {
        $sql = <<<SQL
            SELECT
                t.id,
                t.name,
                t.owner_identifier,
                t.last_used_at,
                t.created_at,
                t.expires_at
            FROM api_token t
            WHERE (t.expires_at IS NULL OR t.expires_at > NOW())
              AND (
                  (t.last_used_at IS NOT NULL AND t.last_used_at < DATE_SUB(NOW(), INTERVAL 6 MONTH))
                  OR (t.last_used_at IS NULL AND t.created_at < DATE_SUB(NOW(), INTERVAL 6 MONTH))
              )
            ORDER BY t.owner_identifier, t.name
        SQL;

        return $this->em->getConnection()->fetchAllAssociative($sql);
    }

    /**
     * Finds domain records that currently live in a domain by naming convention only,
     * when a more specific domain already exists in DashDDI that should own them
     * (e.g. a record hostnamed "host.switches" in "example.com" when "switches.example.com"
     * also exists). Moving a record rewrites its hostname to be relative to the more
     * specific domain while preserving its resulting FQDN.
     *
     * Deliberately excludes categories of record that must stay put even though
     * they match by name, since BIND/DNSSEC need them co-located with the
     * delegating zone:
     *   - the NS record that itself delegates to the more specific domain
     *   - the DS record (DNSSEC delegation signer) at that same delegation point
     *   - any A/AAAA record referenced as the target of an NS record in the same
     *     source domain (glue for that domain's own delegations)
     * See countExcludedReparentRecords() for a count of what was excluded and why.
     *
     * Each row contains: record_id, hostname, record_type, source_domain_id,
     * source_domain_name, target_domain_id, target_domain_name, new_hostname,
     * view_warning (nullable string).
     */
    public function findReparentableDnsRecords(): array
    {
        return $this->computeReparentCandidates()['toMove'];
    }

    /**
     * Number of records left in place by findReparentableDnsRecords() because they
     * are NS/DS delegation records or their glue A/AAAA counterparts.
     */
    public function countExcludedReparentRecords(): int
    {
        return $this->computeReparentCandidates()['excludedCount'];
    }

    /**
     * Removes any views from $record that are not valid for $targetDomain (via
     * DnsViewResolver), for interface-linked A/AAAA records being moved into it.
     * No-op for records that aren't view-constrained in the first place.
     */
    public function narrowViewsForDomain(DomainRecord $record, Domain $targetDomain): void
    {
        $iface = $record->getNetworkInterface();
        if ($iface === null || !in_array($record->getType(), [RecordType::A, RecordType::AAAA], true)) {
            return;
        }

        $allowedIds = array_map(
            fn($v) => $v->getId(),
            $this->viewResolver->availableViewsFor($targetDomain, $iface->getSubnet()),
        );

        foreach ($record->getViews()->toArray() as $view) {
            if (!in_array($view->getId(), $allowedIds, true)) {
                $record->removeView($view);
            }
        }
    }

    // ── private helpers ───────────────────────────────────────────────────────

    private function computeReparentCandidates(): array
    {
        if ($this->reparentCandidatesCache !== null) {
            return $this->reparentCandidatesCache;
        }

        $domains = $this->em->getRepository(Domain::class)->findAll();

        $byLowerName = [];
        foreach ($domains as $d) {
            $byLowerName[strtolower($d->getName())] = $d;
        }

        // NS delegation targets, grouped by the domain the NS record itself lives in —
        // glue only counts if it's co-located with the NS record that needs it.
        $nsTargetsByDomainId = [];
        foreach ($domains as $d) {
            foreach ($d->getRecords() as $r) {
                if ($r->getType() !== RecordType::NS) {
                    continue;
                }
                $nsTargetsByDomainId[$d->getId()][] = $this->normalizeNsTarget($r->getValue(), $d);
            }
        }

        $toMove = [];
        $excludedCount = 0;

        foreach ($domains as $source) {
            foreach ($source->getRecords() as $record) {
                $match = $this->deepestMatchingChild($record->getHostname(), $source, $byLowerName);
                if ($match === null) {
                    continue;
                }
                [$targetDomain, $newHostname] = $match;

                if (in_array($record->getType(), [RecordType::NS, RecordType::DS], true) && $newHostname === '@') {
                    $excludedCount++;
                    continue;
                }

                if (in_array($record->getType(), [RecordType::A, RecordType::AAAA], true)) {
                    $fqdn = strtolower(rtrim($record->getFullyQualifiedHostname(), '.'));
                    $localNsTargets = $nsTargetsByDomainId[$source->getId()] ?? [];
                    if (in_array($fqdn, $localNsTargets, true)) {
                        $excludedCount++;
                        continue;
                    }
                }

                $toMove[] = [
                    'record_id'          => $record->getId(),
                    'hostname'           => $record->getHostname(),
                    'record_type'        => $record->getType()->value,
                    'source_domain_id'   => $source->getId(),
                    'source_domain_name' => $source->getName(),
                    'target_domain_id'   => $targetDomain->getId(),
                    'target_domain_name' => $targetDomain->getName(),
                    'new_hostname'       => $newHostname,
                    'view_warning'       => $this->computeViewWarning($record, $targetDomain),
                ];
            }
        }

        usort($toMove, fn($a, $b) => [$a['source_domain_name'], $a['hostname']] <=> [$b['source_domain_name'], $b['hostname']]);

        return $this->reparentCandidatesCache = ['toMove' => $toMove, 'excludedCount' => $excludedCount];
    }

    /**
     * Finds the most specific existing domain that a record's hostname belongs under,
     * given the domain it currently lives in. A candidate domain C qualifies when its
     * name is C = "<labelChain>.<source domain name>" and the record's hostname ends
     * with (or exactly equals) that label chain. When more than one candidate matches
     * (nested domains both exist), the one with the longest label chain wins.
     *
     * Returns [Domain $targetDomain, string $newHostname] or null if no domain matches.
     */
    private function deepestMatchingChild(string $hostname, Domain $source, array $byLowerName): ?array
    {
        if ($hostname === '' || $hostname === '@') {
            return null;
        }

        $hostLabels = array_map('strtolower', explode('.', rtrim($hostname, '.')));
        $suffix     = '.' . strtolower($source->getName());

        $bestDomain = null;
        $bestChainLabelCount = 0;

        foreach ($byLowerName as $nameLower => $candidate) {
            if ($candidate === $source || !str_ends_with($nameLower, $suffix)) {
                continue;
            }

            $chain = substr($nameLower, 0, -strlen($suffix));
            if ($chain === '') {
                continue;
            }

            $chainLabels = explode('.', $chain);
            $n = count($chainLabels);
            if (count($hostLabels) < $n || array_slice($hostLabels, -$n) !== $chainLabels) {
                continue;
            }

            if ($bestDomain === null || $n > $bestChainLabelCount) {
                $bestDomain = $candidate;
                $bestChainLabelCount = $n;
            }
        }

        if ($bestDomain === null) {
            return null;
        }

        $originalLabels = explode('.', rtrim($hostname, '.'));
        $remaining      = array_slice($originalLabels, 0, count($originalLabels) - $bestChainLabelCount);
        $newHostname    = empty($remaining) ? '@' : implode('.', $remaining);

        return [$bestDomain, $newHostname];
    }

    /**
     * Resolves an NS record's value to an absolute, lowercased FQDN (no trailing dot),
     * treating a trailing-dot value as already absolute and any other value as relative
     * to $domain — matching BIND's own $ORIGIN-relative semantics for zone file names.
     */
    private function normalizeNsTarget(string $value, Domain $domain): string
    {
        $value = trim($value);
        $zone   = rtrim($domain->getName(), '.');

        if ($value === '' || $value === '@') {
            return strtolower($zone);
        }
        if (str_ends_with($value, '.')) {
            return strtolower(rtrim($value, '.'));
        }
        return strtolower($value . '.' . $zone);
    }

    private function computeViewWarning(DomainRecord $record, Domain $targetDomain): ?string
    {
        $iface = $record->getNetworkInterface();
        if ($iface === null || !in_array($record->getType(), [RecordType::A, RecordType::AAAA], true)) {
            return null;
        }

        $allowedIds = array_map(
            fn($v) => $v->getId(),
            $this->viewResolver->availableViewsFor($targetDomain, $iface->getSubnet()),
        );

        $dropped = array_filter(
            $record->getViews()->toArray(),
            fn($v) => !in_array($v->getId(), $allowedIds, true),
        );

        if (empty($dropped)) {
            return null;
        }

        $names = array_map(fn($v) => $v->getName(), $dropped);
        return sprintf(
            'View%s %s not available on the target domain and will be removed',
            count($names) !== 1 ? 's' : '',
            implode(', ', $names),
        );
    }

    private function fetchCnameTargetRows(?int $cnameId = null): array
    {
        // Match a CNAME's value against A/AAAA record hostnames three ways:
        //   1. Same domain, value is the bare hostname
        //   2. value is hostname.domain (FQDN without trailing dot)
        //   3. value is hostname.domain. (FQDN with trailing dot)
        $where = $cnameId !== null ? 'AND cname.id = :cname_id' : '';

        $sql = <<<SQL
            SELECT
                cname.id                    AS record_id,
                cname.hostname,
                cname.value                 AS cname_target,
                d.id                        AS domain_id,
                d.name                      AS domain_name,
                target.type                 AS target_type,
                target.network_interface_id AS target_iface_id,
                target.virtual_ip_id        AS target_vip_id,
                ni.id                       AS iface_id,
                h.name                      AS host_name,
                ni.name                     AS iface_name,
                vip.id                      AS vip_id,
                vip.label                   AS vip_label
            FROM domain_record cname
            JOIN domain d ON cname.domain_id = d.id
            JOIN domain_record target ON (
                target.type IN ('A', 'AAAA')
                AND (target.network_interface_id IS NOT NULL OR target.virtual_ip_id IS NOT NULL)
            )
            JOIN domain td ON target.domain_id = td.id
            LEFT JOIN network_interface ni ON ni.id  = target.network_interface_id AND ni.deleted_at IS NULL
            LEFT JOIN host h               ON h.id   = ni.host_id
            LEFT JOIN virtual_ip vip       ON vip.id = target.virtual_ip_id        AND vip.deleted_at IS NULL
            WHERE cname.type = 'CNAME'
              AND cname.network_interface_id IS NULL
              AND cname.virtual_ip_id IS NULL
              AND (
                  (cname.domain_id = target.domain_id AND cname.value = target.hostname)
                  OR cname.value = CONCAT(target.hostname, '.', td.name)
                  OR cname.value = CONCAT(target.hostname, '.', td.name, '.')
              )
              $where
            ORDER BY d.name, cname.hostname
        SQL;

        $params = $cnameId !== null ? ['cname_id' => $cnameId] : [];
        return $this->em->getConnection()->fetchAllAssociative($sql, $params);
    }

    private function groupCnameRows(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['record_id']][] = $row;
        }

        $results = [];
        foreach ($grouped as $matches) {
            $first = $matches[0];
            $results[] = [
                'record_id'    => (int) $first['record_id'],
                'hostname'     => $first['hostname'],
                'cname_target' => $first['cname_target'],
                'domain_id'    => (int) $first['domain_id'],
                'domain_name'  => $first['domain_name'],
                'conversions'  => array_map(fn($m) => $this->rowToTarget($m), $matches),
            ];
        }

        return $results;
    }

    private function rowToTarget(array $row): array
    {
        $isIface = $row['target_iface_id'] !== null;
        return [
            'target_type'    => $row['target_type'],
            'match_type'     => $isIface ? 'interface' : 'vip',
            'match_id'       => (int) ($isIface ? $row['iface_id'] : $row['vip_id']),
            'match_label'    => $isIface ? $row['host_name'] : $row['vip_label'],
            'match_sublabel' => $isIface ? $row['iface_name'] : null,
        ];
    }
}
