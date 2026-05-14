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
     * All interfaces (excluding placeholder 00:00:00:00:00:00) with subnet and
     * canonical name pre-fetched to avoid N+1 during authorize-file generation.
     *
     * @return NetworkInterface[]
     */
    public function findAllForRadiusAuth(): array
    {
        return $this->createQueryBuilder('ni')
            ->addSelect('s', 'iname', 'd')
            ->leftJoin('ni.subnet', 's')
            ->leftJoin('ni.names', 'iname')
            ->leftJoin('iname.domain', 'd')
            ->where('ni.macAddress != :zero')
            ->setParameter('zero', '00:00:00:00:00:00')
            ->orderBy('ni.macAddress', 'ASC')
            ->getQuery()
            ->getResult();
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
