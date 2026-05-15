<?php

namespace App\Repository;

use App\Entity\ClearpassAuthLog;
use App\Entity\ClearpassServer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

class ClearpassAuthLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClearpassAuthLog::class);
    }

    public function search(
        string $mac,
        string $username,
        ?ClearpassServer $server,
        ?string $status,
        int $page,
        int $perPage = 50,
    ): Paginator {
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
        if ($server !== null) {
            $qb->andWhere('l.clearpassServer = :server')
               ->setParameter('server', $server);
        }
        if ($status !== '') {
            $qb->andWhere('l.authStatus = :status')
               ->setParameter('status', $status);
        }

        $qb->setFirstResult(($page - 1) * $perPage)
           ->setMaxResults($perPage);

        return new Paginator($qb);
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

    /** Returns the largest session ID stored for the given server, or null if none. */
    public function findMaxSessionId(ClearpassServer $server): ?string
    {
        return $this->createQueryBuilder('l')
            ->select('MAX(l.sessionId)')
            ->where('l.clearpassServer = :server')
            ->setParameter('server', $server)
            ->getQuery()
            ->getSingleScalarResult();
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

    /** Returns distinct auth status values present in the table. */
    public function findDistinctStatuses(): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('DISTINCT l.authStatus')
            ->where('l.authStatus IS NOT NULL')
            ->orderBy('l.authStatus', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'authStatus');
    }
}
