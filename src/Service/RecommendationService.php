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
     * record_type, match_type ('interface'|'vip'), match_id, match_label.
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
                h.name       AS match_label
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
                h.name       AS match_label
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
                vip.label    AS match_label
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
                vip.label    AS match_label
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
}
