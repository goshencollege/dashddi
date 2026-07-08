<?php

namespace App\Repository;

use App\Entity\NetworkInterface;
use App\Entity\VirtualIp;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class VirtualIpRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VirtualIp::class);
    }

    /** @return VirtualIp[] */
    public function findByMemberInterface(NetworkInterface $interface): array
    {
        return $this->createQueryBuilder('v')
            ->join('v.memberInterfaces', 'm')
            ->andWhere('m = :iface')
            ->setParameter('iface', $interface)
            ->orderBy('v.label', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
