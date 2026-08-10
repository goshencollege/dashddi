<?php

namespace App\Repository;

use App\Entity\Domain;
use App\Entity\DnssecDisableRequest;
use App\Entity\Subnet;
use App\Enum\DnssecDisableStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DnssecDisableRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DnssecDisableRequest::class);
    }

    public function findActiveForDomain(Domain $domain): ?DnssecDisableRequest
    {
        return $this->createQueryBuilder('r')
            ->where('r.domain = :domain')
            ->andWhere('r.status NOT IN (:terminal)')
            ->setParameter('domain', $domain)
            ->setParameter('terminal', [DnssecDisableStatus::Complete, DnssecDisableStatus::Failed])
            ->orderBy('r.startedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findActiveForSubnet(Subnet $subnet): ?DnssecDisableRequest
    {
        return $this->createQueryBuilder('r')
            ->where('r.subnet = :subnet')
            ->andWhere('r.status NOT IN (:terminal)')
            ->setParameter('subnet', $subnet)
            ->setParameter('terminal', [DnssecDisableStatus::Complete, DnssecDisableStatus::Failed])
            ->orderBy('r.startedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
