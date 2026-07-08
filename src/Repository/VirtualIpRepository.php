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

    /**
     * Returns a map of interface_id => VirtualIp[] for the given interface IDs.
     * Uses two focused queries to avoid N+1: one scalar query for pairs, one
     * entity query for the VIP objects.
     *
     * @param  int[]  $interfaceIds
     * @return array<int, VirtualIp[]>
     */
    public function findMapByInterfaceIds(array $interfaceIds): array
    {
        if (empty($interfaceIds)) {
            return [];
        }

        $pairs = $this->getEntityManager()->createQuery(
            'SELECT v.id AS vip_id, m.id AS iface_id
             FROM App\Entity\VirtualIp v
             JOIN v.memberInterfaces m
             WHERE m.id IN (:ids) AND v.deletedAt IS NULL
             ORDER BY v.label ASC'
        )
            ->setParameter('ids', $interfaceIds)
            ->getScalarResult();

        if (empty($pairs)) {
            return [];
        }

        $vipIds  = array_unique(array_column($pairs, 'vip_id'));
        $vipObjs = $this->findBy(['id' => $vipIds]);
        $vipById = [];
        foreach ($vipObjs as $v) {
            $vipById[$v->getId()] = $v;
        }

        $map = [];
        foreach ($pairs as $pair) {
            $map[(int) $pair['iface_id']][] = $vipById[(int) $pair['vip_id']];
        }

        return $map;
    }

    /**
     * Full-text search across label and IP addresses. Returns non-deleted VIPs only.
     *
     * @return VirtualIp[]
     */
    public function search(string $q, int $limit = 20): array
    {
        return $this->createQueryBuilder('v')
            ->leftJoin('v.ipAddress', 'ip')
            ->leftJoin('v.ipv6Address', 'ip6')
            ->andWhere('v.deletedAt IS NULL')
            ->andWhere('v.label LIKE :q OR ip.address LIKE :q OR ip6.address LIKE :q')
            ->setParameter('q', '%' . $q . '%')
            ->orderBy('v.label', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns non-deleted VIPs on the same subnet as $interface that do not
     * already have $interface as a member.
     *
     * @return VirtualIp[]
     */
    public function findLinkableForInterface(NetworkInterface $interface): array
    {
        if (!$interface->getSubnet()) {
            return [];
        }

        return $this->createQueryBuilder('v')
            ->leftJoin('v.memberInterfaces', 'm')
            ->andWhere('v.subnet = :subnet')
            ->andWhere('v.deletedAt IS NULL')
            ->andWhere(':iface NOT MEMBER OF v.memberInterfaces')
            ->setParameter('subnet', $interface->getSubnet())
            ->setParameter('iface', $interface)
            ->orderBy('v.label', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
