<?php

namespace App\Service;

use App\Entity\DomainRecord;
use App\Entity\NetworkInterface;
use App\Entity\VirtualIp;
use App\Enum\RecordType;
use Doctrine\ORM\EntityManagerInterface;

class RecommendationService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

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

    // ── private helpers ───────────────────────────────────────────────────────

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
