<?php

namespace App\Repository;

use App\Entity\DhcpLease;
use App\Entity\DhcpServer;
use App\Entity\Subnet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

class DhcpLeaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DhcpLease::class);
    }

    public function search(string $mac, string $ip, ?Subnet $subnet, ?DhcpServer $server, int $page, int $perPage = 50): Paginator
    {
        $qb = $this->createQueryBuilder('l')
            ->leftJoin('l.subnet', 's')
            ->addSelect('s')
            ->leftJoin('l.dhcpServer', 'srv')
            ->addSelect('srv')
            ->orderBy('l.leaseStart', 'DESC');

        if ($mac !== '') {
            $qb->andWhere('l.macAddress LIKE :mac')
               ->setParameter('mac', '%' . strtolower($mac) . '%');
        }
        if ($ip !== '') {
            $qb->andWhere('l.ipAddress LIKE :ip')
               ->setParameter('ip', '%' . $ip . '%');
        }
        if ($subnet !== null) {
            $qb->andWhere('l.subnet = :subnet')
               ->setParameter('subnet', $subnet);
        }
        if ($server !== null) {
            $qb->andWhere('l.dhcpServer = :server')
               ->setParameter('server', $server);
        }

        $qb->setFirstResult(($page - 1) * $perPage)
           ->setMaxResults($perPage);

        return new Paginator($qb);
    }

    /**
     * Returns a map of macAddress => most-recent DhcpLease for the given MAC list.
     * Used to display DHCP-assigned IPs alongside static IPs in list views.
     *
     * Uses a correlated subquery so only one lease per MAC is returned — without
     * this, the query loads every historical lease for each MAC address, which can
     * be tens of thousands of rows for long-lived hosts.
     *
     * @param  string[] $macs
     * @return array<string, DhcpLease>
     */
    public function findLatestByMacs(array $macs): array
    {
        if (empty($macs)) {
            return [];
        }

        $leases = $this->createQueryBuilder('l')
            ->where('l.macAddress IN (:macs)')
            ->andWhere('l.leaseStart = (SELECT MAX(l2.leaseStart) FROM App\Entity\DhcpLease l2 WHERE l2.macAddress = l.macAddress)')
            ->setParameter('macs', array_map('strtolower', $macs))
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($leases as $lease) {
            $map[$lease->getMacAddress()] = $lease;
        }
        return $map;
    }

    /** Most recent leases for a given MAC address, newest first. */
    public function findByMac(string $mac, int $limit = 10): array
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.subnet', 's')->addSelect('s')
            ->leftJoin('l.dhcpServer', 'srv')->addSelect('srv')
            ->where('l.macAddress = :mac')
            ->setParameter('mac', strtolower($mac))
            ->orderBy('l.leaseStart', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns MACs that DHCPed into a different subnet after $cutover vs. before.
     * Each row: mac_address, pre_subnet_id, pre_subnet_cidr, pre_vlan, pre_ts,
     *           post_subnet_id, post_subnet_cidr, post_vlan, post_ts,
     *           iface_id (nullable), host_name (nullable).
     */
    public function findSubnetChanges(\DateTimeImmutable $cutover): array
    {
        $sql = <<<SQL
            SELECT
                pre.mac_address,
                pre_s.id        AS pre_subnet_id,
                pre_s.ipv4_cidr AS pre_subnet_cidr,
                pre_s.vlan      AS pre_vlan,
                pre.lease_start AS pre_ts,
                post_s.id        AS post_subnet_id,
                post_s.ipv4_cidr AS post_subnet_cidr,
                post_s.vlan      AS post_vlan,
                post.lease_start AS post_ts,
                ni.id   AS iface_id,
                h.name  AS host_name
            FROM (
                SELECT dl.mac_address, dl.subnet_id, dl.lease_start
                FROM dhcp_lease dl
                INNER JOIN (
                    SELECT mac_address, MAX(lease_start) AS max_ts
                    FROM dhcp_lease
                    WHERE lease_start < :cutover AND subnet_id IS NOT NULL
                    GROUP BY mac_address
                ) anchor ON dl.mac_address = anchor.mac_address
                         AND dl.lease_start = anchor.max_ts
                WHERE dl.subnet_id IS NOT NULL
            ) pre
            JOIN (
                SELECT dl.mac_address, dl.subnet_id, dl.lease_start
                FROM dhcp_lease dl
                INNER JOIN (
                    SELECT mac_address, MAX(lease_start) AS max_ts
                    FROM dhcp_lease
                    WHERE lease_start >= :cutover AND subnet_id IS NOT NULL
                    GROUP BY mac_address
                ) anchor ON dl.mac_address = anchor.mac_address
                         AND dl.lease_start = anchor.max_ts
                WHERE dl.subnet_id IS NOT NULL
            ) post ON pre.mac_address = post.mac_address
                   AND pre.subnet_id != post.subnet_id
            JOIN subnet pre_s  ON pre_s.id  = pre.subnet_id
            JOIN subnet post_s ON post_s.id = post.subnet_id
            LEFT JOIN network_interface ni ON ni.mac_address = pre.mac_address
            LEFT JOIN host h ON h.id = ni.host_id
            ORDER BY pre.mac_address
        SQL;

        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            $sql,
            ['cutover' => $cutover->format('Y-m-d H:i:s')],
        );
    }

    /**
     * Delete leases older than their retention period.
     *
     * Per-subnet retention takes priority. $defaultDays is used for leases
     * whose subnet has no explicit retention set and for orphaned leases
     * (subnet deleted / not yet matched).
     */
    public function purgeByRetention(?int $defaultDays = null): int
    {
        // Subnets with an explicit retention
        $subnets = $this->getEntityManager()
            ->createQuery('SELECT s FROM App\Entity\Subnet s WHERE s.leaseRetentionDays IS NOT NULL')
            ->getResult();

        $deleted = 0;
        foreach ($subnets as $subnet) {
            $cutoff = new \DateTimeImmutable(
                '-' . $subnet->getLeaseRetentionDays() . ' days'
            );
            $deleted += $this->createQueryBuilder('l')
                ->delete()
                ->where('l.subnet = :subnet')
                ->andWhere('l.createdAt < :cutoff')
                ->setParameter('subnet', $subnet)
                ->setParameter('cutoff', $cutoff)
                ->getQuery()
                ->execute();
        }

        // Apply the default to everything not covered by a per-subnet setting.
        if ($defaultDays !== null) {
            $cutoff = new \DateTimeImmutable('-' . $defaultDays . ' days');
            $qb = $this->createQueryBuilder('l')
                ->delete()
                ->where('l.createdAt < :cutoff')
                ->setParameter('cutoff', $cutoff);

            if (!empty($subnets)) {
                // Exclude subnets that were already handled above.
                $qb->andWhere(
                    $qb->expr()->orX(
                        $qb->expr()->isNull('l.subnet'),
                        $qb->expr()->notIn('l.subnet', ':explicitSubnets')
                    )
                )->setParameter('explicitSubnets', $subnets);
            }

            $deleted += $qb->getQuery()->execute();
        }

        return $deleted;
    }
}
