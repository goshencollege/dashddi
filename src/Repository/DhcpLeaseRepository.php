<?php

namespace App\Repository;

use App\Entity\DhcpLease;
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

    public function search(string $mac, string $ip, ?Subnet $subnet, int $page, int $perPage = 50): Paginator
    {
        $qb = $this->createQueryBuilder('l')
            ->leftJoin('l.subnet', 's')
            ->addSelect('s')
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

        $qb->setFirstResult(($page - 1) * $perPage)
           ->setMaxResults($perPage);

        return new Paginator($qb);
    }

    /**
     * Returns a map of macAddress => most-recent DhcpLease for the given MAC list.
     * Used to display DHCP-assigned IPs alongside static IPs in list views.
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
            ->setParameter('macs', array_map('strtolower', $macs))
            ->orderBy('l.leaseStart', 'DESC')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($leases as $lease) {
            $mac = $lease->getMacAddress();
            if (!isset($map[$mac])) {
                $map[$mac] = $lease;
            }
        }
        return $map;
    }

    /** Most recent leases for a given MAC address, newest first. */
    public function findByMac(string $mac, int $limit = 10): array
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.subnet', 's')->addSelect('s')
            ->where('l.macAddress = :mac')
            ->setParameter('mac', strtolower($mac))
            ->orderBy('l.leaseStart', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Delete leases older than the retention period for each subnet. */
    public function purgeByRetention(): int
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

        return $deleted;
    }
}
