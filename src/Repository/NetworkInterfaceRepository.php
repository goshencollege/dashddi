<?php

namespace App\Repository;

use App\Entity\NetworkInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NetworkInterface>
 */
class NetworkInterfaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NetworkInterface::class);
    }

    /**
     * @param  string[] $macs
     * @return array<string, NetworkInterface>  keyed by macAddress
     */
    public function findByMacs(array $macs): array
    {
        if (empty($macs)) {
            return [];
        }

        $rows = $this->createQueryBuilder('i')
            ->where('i.macAddress IN (:macs)')
            ->setParameter('macs', array_map('strtolower', $macs))
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $iface) {
            $map[$iface->getMacAddress()] = $iface;
        }
        return $map;
    }
}
