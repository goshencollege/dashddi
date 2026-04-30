<?php

namespace App\Repository;

use App\Entity\Domain;
use App\Entity\DnssecKskRollover;
use App\Entity\Subnet;
use App\Enum\KskRolloverStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DnssecKskRolloverRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DnssecKskRollover::class);
    }

    public function findActiveForDomain(Domain $domain): ?DnssecKskRollover
    {
        return $this->createQueryBuilder('r')
            ->where('r.domain = :domain')
            ->andWhere('r.status NOT IN (:terminal)')
            ->setParameter('domain', $domain)
            ->setParameter('terminal', [KskRolloverStatus::Complete, KskRolloverStatus::Failed])
            ->orderBy('r.startedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findActiveForSubnet(Subnet $subnet): ?DnssecKskRollover
    {
        return $this->createQueryBuilder('r')
            ->where('r.subnet = :subnet')
            ->andWhere('r.status NOT IN (:terminal)')
            ->setParameter('subnet', $subnet)
            ->setParameter('terminal', [KskRolloverStatus::Complete, KskRolloverStatus::Failed])
            ->orderBy('r.startedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
