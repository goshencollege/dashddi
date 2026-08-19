<?php

namespace App\Repository;

use App\Entity\SwitchPortLog;
use App\Enum\SwitchPortLogSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SwitchPortLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SwitchPortLog::class);
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

    /**
     * Most recent log source per interface — lets a "last seen" display show
     * whether that timestamp came from a ClearPass auth event or a live switch
     * scan, rather than assuming it was always ClearPass.
     *
     * @param  int[] $ifaceIds
     * @return array<int, string> interface id => 'clearpass'|'live_scan'
     */
    public function findLatestSourcesByInterfaceIds(array $ifaceIds): array
    {
        if (empty($ifaceIds)) {
            return [];
        }

        $rows = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.networkInterface) AS ifaceId', 'l.source AS source')
            ->where('l.networkInterface IN (:ids)')
            ->setParameter('ids', $ifaceIds)
            ->orderBy('l.observedAt', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            if (!isset($map[$row['ifaceId']])) {
                $map[$row['ifaceId']] = $row['source'] instanceof SwitchPortLogSource
                    ? $row['source']->value
                    : (string) $row['source'];
            }
        }

        return $map;
    }
}
