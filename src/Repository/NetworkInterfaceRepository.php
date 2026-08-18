<?php

namespace App\Repository;

use App\Entity\IpAddress;
use App\Entity\Ipv6Address;
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
            ->leftJoin('ni.domainRecords', 'iname')
            ->leftJoin('iname.domain', 'd')
            ->where('ni.macAddress != :zero')
            ->andWhere('ni.deletedAt IS NULL')
            ->setParameter('zero', '00:00:00:00:00:00')
            ->orderBy('ni.macAddress', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findMacByIpAddress(IpAddress $ip): ?string
    {
        $result = $this->createQueryBuilder('ni')
            ->select('ni.macAddress')
            ->where('ni.ipAddress = :ip')
            ->andWhere('ni.deletedAt IS NULL')
            ->setParameter('ip', $ip)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result ? $result['macAddress'] : null;
    }

    public function findMacByIpv6Address(Ipv6Address $ip): ?string
    {
        $result = $this->createQueryBuilder('ni')
            ->select('ni.macAddress')
            ->where('ni.ipv6Address = :ip')
            ->andWhere('ni.deletedAt IS NULL')
            ->setParameter('ip', $ip)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result ? $result['macAddress'] : null;
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
            ->andWhere('i.deletedAt IS NULL')
            ->setParameter('macs', array_map('strtolower', $macs))
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $iface) {
            $map[$iface->getMacAddress()] = $iface;
        }
        return $map;
    }

    /** Finds an active (non-deleted) interface by MAC address. */
    public function findActiveByMac(string $mac): ?NetworkInterface
    {
        return $this->createQueryBuilder('ni')
            ->where('ni.macAddress = :mac')
            ->andWhere('ni.deletedAt IS NULL')
            ->setParameter('mac', $mac)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Finds a soft-deleted interface by MAC address. */
    public function findDeletedByMac(string $mac): ?NetworkInterface
    {
        return $this->createQueryBuilder('ni')
            ->where('ni.macAddress = :mac')
            ->andWhere('ni.deletedAt IS NOT NULL')
            ->setParameter('mac', $mac)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Finds an active interface by its IPv4 address string, with host eager-loaded. */
    public function findByIpString(string $ip): ?NetworkInterface
    {
        return $this->createQueryBuilder('ni')
            ->addSelect('h')
            ->join('ni.ipAddress', 'a')
            ->leftJoin('ni.host', 'h')
            ->where('a.address = :ip')
            ->andWhere('ni.deletedAt IS NULL')
            ->setParameter('ip', $ip)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Active interfaces whose cached switch attachment (switchIp) matches one of the
     * given IPs — i.e. devices currently known to be connected to that switch.
     *
     * @param  string[] $switchIps
     * @return NetworkInterface[]
     */
    public function findConnectedToSwitchIps(array $switchIps): array
    {
        $switchIps = array_values(array_filter($switchIps));
        if (empty($switchIps)) {
            return [];
        }

        $interfaces = $this->createQueryBuilder('ni')
            ->addSelect('h')
            ->leftJoin('ni.host', 'h')
            ->where('ni.switchIp IN (:switchIps)')
            ->andWhere('ni.switchPort IS NOT NULL')
            ->andWhere('ni.deletedAt IS NULL')
            ->setParameter('switchIps', $switchIps)
            ->getQuery()
            ->getResult();

        usort($interfaces, fn ($a, $b) => strnatcasecmp($a->getSwitchPort(), $b->getSwitchPort()));

        return $interfaces;
    }

    /**
     * Full-text search across host name, interface name, IPv4, and IPv6 address.
     *
     * @return NetworkInterface[]
     */
    public function search(string $q, int $limit = 20, ?\App\Entity\Subnet $subnet = null): array
    {
        $like = '%' . $q . '%';
        $qb = $this->createQueryBuilder('i')
            ->leftJoin('i.host', 'h')
            ->leftJoin('i.ipAddress', 'ip')
            ->leftJoin('i.ipv6Address', 'ip6')
            ->where('i.deletedAt IS NULL')
            ->andWhere('h.name LIKE :q OR i.name LIKE :q OR ip.address LIKE :q OR ip6.address LIKE :q OR i.macAddress LIKE :q')
            ->setParameter('q', $like)
            ->orderBy('h.name', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->setMaxResults($limit);
        if ($subnet !== null) {
            $qb->andWhere('i.subnet = :subnet')->setParameter('subnet', $subnet);
        }
        return $qb->getQuery()->getResult();
    }

    public function purgeDeletedBefore(\DateTimeImmutable $before): int
    {
        $entities = $this->createQueryBuilder('ni')
            ->where('ni.deletedAt IS NOT NULL')
            ->andWhere('ni.deletedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->getResult();

        foreach ($entities as $entity) {
            $this->getEntityManager()->remove($entity);
        }
        if (!empty($entities)) {
            $this->getEntityManager()->flush();
        }

        return count($entities);
    }
}
