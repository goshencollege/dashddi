<?php

namespace App\Repository;

use App\Entity\ClearpassAuthLog;
use App\Entity\ClearpassServer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ClearpassAuthLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClearpassAuthLog::class);
    }

    /**
     * @return array{items: ClearpassAuthLog[], hasMore: bool}
     */
    public function search(
        string $mac,
        string $username,
        string $role,
        string $vlan,
        string $protocol,
        string $service,
        string $nasIp,
        string $nasPortId,
        int $page,
        int $perPage = 50,
    ): array {
        $qb = $this->createQueryBuilder('l')
            ->leftJoin('l.clearpassServer', 's')->addSelect('s')
            ->leftJoin('l.networkInterface', 'i')->addSelect('i')
            ->orderBy('l.authTimestamp', 'DESC');

        if ($mac !== '') {
            $qb->andWhere('l.macAddress LIKE :mac')
               ->setParameter('mac', '%' . strtolower($mac) . '%');
        }
        if ($username !== '') {
            $qb->andWhere('l.username LIKE :username')
               ->setParameter('username', '%' . $username . '%');
        }
        if ($role !== '') {
            $qb->andWhere('l.role = :role')
               ->setParameter('role', $role);
        }
        if ($vlan !== '') {
            $qb->andWhere('l.vlan = :vlan')
               ->setParameter('vlan', $vlan);
        }
        if ($protocol !== '') {
            $qb->andWhere('l.authProtocol = :protocol')
               ->setParameter('protocol', $protocol);
        }
        if ($service !== '') {
            $qb->andWhere('l.service = :service')
               ->setParameter('service', $service);
        }
        if ($nasIp !== '') {
            $qb->andWhere('l.nasIp LIKE :nasIp')
               ->setParameter('nasIp', '%' . $nasIp . '%');
        }
        if ($nasPortId !== '') {
            $qb->andWhere('l.nasPortId LIKE :nasPortId')
               ->setParameter('nasPortId', '%' . $nasPortId . '%');
        }

        $qb->setFirstResult(($page - 1) * $perPage)
           ->setMaxResults($perPage + 1);

        $results = $qb->getQuery()->getResult();
        return [
            'items'   => array_slice($results, 0, $perPage),
            'hasMore' => count($results) > $perPage,
        ];
    }

    /** Most recent auth logs for a given MAC, newest first. */
    public function findByMac(string $mac, int $limit = 10): array
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.clearpassServer', 's')->addSelect('s')
            ->where('l.macAddress = :mac')
            ->setParameter('mac', strtolower($mac))
            ->orderBy('l.authTimestamp', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Returns a map of macAddress => most-recent ClearpassAuthLog for the given MAC list. */
    public function findLatestByMacs(array $macs): array
    {
        if (empty($macs)) {
            return [];
        }

        $logs = $this->createQueryBuilder('l')
            ->where('l.macAddress IN (:macs)')
            ->setParameter('macs', array_map('strtolower', $macs))
            ->orderBy('l.authTimestamp', 'DESC')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($logs as $log) {
            $mac = $log->getMacAddress();
            if (!isset($map[$mac])) {
                $map[$mac] = $log;
            }
        }
        return $map;
    }

    /**
     * Returns the most recent auth log for the given MAC that has switch info (nasIp),
     * optionally restricted to entries newer than $cutoff.
     */
    public function findLatestWithSwitchInfoByMac(string $mac, ?\DateTimeImmutable $cutoff): ?ClearpassAuthLog
    {
        $qb = $this->createQueryBuilder('l')
            ->where('l.macAddress = :mac')
            ->andWhere('l.nasIp IS NOT NULL')
            ->setParameter('mac', strtolower($mac))
            ->orderBy('l.authTimestamp', 'DESC')
            ->setMaxResults(1);

        if ($cutoff !== null) {
            $qb->andWhere('l.authTimestamp >= :cutoff')
               ->setParameter('cutoff', $cutoff);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Returns which of the given session IDs already exist for this server.
     * Used to skip duplicates without a per-row findOneBy.
     *
     * @param  string[] $sessionIds
     * @return string[]
     */
    public function findExistingSessionIds(ClearpassServer $server, array $sessionIds): array
    {
        if (empty($sessionIds)) {
            return [];
        }

        return array_column(
            $this->createQueryBuilder('l')
                ->select('l.sessionId')
                ->where('l.clearpassServer = :server')
                ->andWhere('l.sessionId IN (:ids)')
                ->setParameter('server', $server)
                ->setParameter('ids', $sessionIds)
                ->getQuery()
                ->getArrayResult(),
            'sessionId'
        );
    }

    /** Returns the most recent authTimestamp stored for the given server, or null if none. */
    public function findLatestAuthTimestamp(ClearpassServer $server): ?\DateTimeImmutable
    {
        $result = $this->createQueryBuilder('l')
            ->select('MAX(l.authTimestamp)')
            ->where('l.clearpassServer = :server')
            ->setParameter('server', $server)
            ->getQuery()
            ->getSingleScalarResult();

        return $result !== null ? new \DateTimeImmutable($result) : null;
    }

    public function purgeOlderThan(\DateTimeImmutable $cutoff): int
    {
        return $this->createQueryBuilder('l')
            ->delete()
            ->where('l.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }

    public function findDistinctRoles(): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('DISTINCT l.role')
            ->where('l.role IS NOT NULL')
            ->orderBy('l.role', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'role');
    }

    public function findDistinctVlans(): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('DISTINCT l.vlan')
            ->where('l.vlan IS NOT NULL')
            ->orderBy('l.vlan', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'vlan');
    }

    public function findDistinctServices(): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('DISTINCT l.service')
            ->where('l.service IS NOT NULL')
            ->orderBy('l.service', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'service');
    }

    public function findDistinctProtocols(): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('DISTINCT l.authProtocol')
            ->where('l.authProtocol IS NOT NULL')
            ->orderBy('l.authProtocol', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'authProtocol');
    }
}
