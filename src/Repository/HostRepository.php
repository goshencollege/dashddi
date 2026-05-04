<?php

namespace App\Repository;

use App\Entity\Host;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Host>
 */
class HostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Host::class);
    }

    /** @return Host[] */
    public function advancedSearch(array $criteria): array
    {
        $qb = $this->createQueryBuilder('h')
            ->leftJoin('h.building', 'b')
            ->leftJoin('h.tags', 'tg')
            ->leftJoin('h.interfaces', 'i')
            ->leftJoin('i.ipAddress', 'ip4')
            ->leftJoin('i.ipv6Address', 'ip6')
            ->leftJoin('i.names', 'n')
            ->leftJoin('n.domain', 'nd')
            ->leftJoin('App\Entity\DhcpLease', 'dl', 'WITH', 'dl.macAddress = i.macAddress');

        if (!empty($criteria['name'])) {
            $qb->andWhere('h.name LIKE :name')
               ->setParameter('name', $this->toLike($criteria['name']));
        }
        if (!empty($criteria['building'])) {
            $qb->andWhere('h.building = :building')
               ->setParameter('building', (int) $criteria['building']);
        }
        if (!empty($criteria['room'])) {
            $qb->andWhere('h.room LIKE :room')
               ->setParameter('room', $this->toLike($criteria['room']));
        }
        if (!empty($criteria['subnet'])) {
            $qb->andWhere('i.subnet = :subnet')
               ->setParameter('subnet', (int) $criteria['subnet']);
        }
        if (!empty($criteria['ip'])) {
            $qb->andWhere($qb->expr()->orX('ip4.address LIKE :ip', 'ip6.address LIKE :ip', 'dl.ipAddress LIKE :ip'))
               ->setParameter('ip', $this->toLike($criteria['ip']));
        }
        if (!empty($criteria['mac'])) {
            $qb->andWhere('i.macAddress LIKE :mac')
               ->setParameter('mac', $this->toLike($criteria['mac']));
        }
        if (!empty($criteria['tag'])) {
            $qb->andWhere('tg.id = :tag')
               ->setParameter('tag', (int) $criteria['tag']);
        }
        if (!empty($criteria['dns'])) {
            $qb->andWhere($qb->expr()->orX(
                'n.name LIKE :dns',
                'nd.name LIKE :dns',
                "CONCAT(n.name, '.', nd.name) LIKE :dns"
            ))->setParameter('dns', $this->toLike($criteria['dns']));
        }

        return $qb->distinct()->orderBy('h.name', 'ASC')->getQuery()->getResult();
    }

    private function toLike(string $value): string
    {
        // * is the user-facing wildcard; if none present, do a contains search
        return str_contains($value, '*')
            ? str_replace('*', '%', $value)
            : '%' . $value . '%';
    }

    /** @return Host[] */
    public function search(string $query): array
    {
        $q  = '%' . $query . '%';
        $qb = $this->createQueryBuilder('h')
            ->leftJoin('h.building', 'b')
            ->leftJoin('h.tags', 'tg')
            ->leftJoin('h.interfaces', 'i')
            ->leftJoin('i.subnet', 's')
            ->leftJoin('i.ipAddress', 'ip4')
            ->leftJoin('i.ipv6Address', 'ip6')
            ->leftJoin('i.names', 'n')
            ->leftJoin('n.domain', 'nd')
            ->leftJoin('App\Entity\DhcpLease', 'dl', 'WITH', 'dl.macAddress = i.macAddress')
            ->where('h.name LIKE :q')
            ->orWhere('b.name LIKE :q')
            ->orWhere('h.room LIKE :q')
            ->orWhere("CONCAT(COALESCE(b.name, ''), COALESCE(h.room, '')) LIKE :q")
            ->orWhere('s.name LIKE :q')
            ->orWhere('ip4.address LIKE :q')
            ->orWhere('ip6.address LIKE :q')
            ->orWhere('dl.ipAddress LIKE :q')
            ->orWhere('i.macAddress LIKE :q')
            ->orWhere('n.name LIKE :q')
            ->orWhere('nd.name LIKE :q')
            ->orWhere("CONCAT(n.name, '.', nd.name) LIKE :q")
            ->orWhere('tg.name LIKE :q')
            ->setParameter('q', $q);

        // If the query is (or contains) a MAC in any delimiter/case style,
        // also match the normalized colon-separated form stored in the DB.
        $hex = preg_replace('/[^0-9a-fA-F]/', '', $query);
        if (strlen($hex) === 12) {
            $normalized = implode(':', str_split(strtolower($hex), 2));
            $qb->orWhere('i.macAddress = :mac')->setParameter('mac', $normalized);
        }

        return $qb
            ->distinct()
            ->orderBy('h.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
