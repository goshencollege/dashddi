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
