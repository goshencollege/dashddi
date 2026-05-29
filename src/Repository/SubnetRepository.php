<?php

namespace App\Repository;

use App\Entity\Subnet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use IPLib\Factory;

/**
 * @extends ServiceEntityRepository<Subnet>
 */
class SubnetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subnet::class);
    }

    // -------------------------------------------------------------------------
    // Paginated listing / search
    // -------------------------------------------------------------------------

    /** @return array{subnets: Subnet[], total: int} */
    public function findAllPaginated(int $page, int $perPage): array
    {
        $offset = max(0, ($page - 1) * $perPage);

        $total = (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.isContainer = false')
            ->getQuery()
            ->getSingleScalarResult();

        $ids = $this->idsForPage(
            $this->createQueryBuilder('s')->andWhere('s.isContainer = false'),
            $offset,
            $perPage
        );

        return ['subnets' => $this->fetchByIds($ids), 'total' => $total];
    }

    /** @return array{subnets: Subnet[], total: int} */
    public function searchPaginated(string $query, int $page, int $perPage): array
    {
        return $this->paginateFilterQuery($this->buildSearchQb($query), $page, $perPage);
    }

    /** @return array{subnets: Subnet[], total: int} */
    public function advancedSearchPaginated(array $criteria, int $page, int $perPage): array
    {
        return $this->paginateFilterQuery($this->buildAdvancedQb($criteria), $page, $perPage);
    }

    private function buildSearchQb(string $query): QueryBuilder
    {
        $q = '%' . $query . '%';

        return $this->createQueryBuilder('s')
            ->leftJoin('s.vrf', 'v')
            ->leftJoin('s.tags', 'tg')
            ->where('s.name LIKE :q')
            ->orWhere('s.ipv4Cidr LIKE :q')
            ->orWhere('s.ipv6Cidr LIKE :q')
            ->orWhere('s.description LIKE :q')
            ->orWhere('s.gateway LIKE :q')
            ->orWhere('v.name LIKE :q')
            ->orWhere('tg.name LIKE :q')
            ->setParameter('q', $q);
    }

    private function buildAdvancedQb(array $criteria): QueryBuilder
    {
        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.vrf', 'v')
            ->leftJoin('s.tags', 'tg');

        if (!empty($criteria['name'])) {
            $qb->andWhere('s.name LIKE :name')
               ->setParameter('name', $this->toLike($criteria['name']));
        }
        if (!empty($criteria['cidr'])) {
            $qb->andWhere($qb->expr()->orX('s.ipv4Cidr LIKE :cidr', 's.ipv6Cidr LIKE :cidr'))
               ->setParameter('cidr', $this->toLike($criteria['cidr']));
        }
        if (!empty($criteria['vlan'])) {
            $qb->andWhere('s.vlan = :vlan')
               ->setParameter('vlan', (int) $criteria['vlan']);
        }
        if (!empty($criteria['gateway'])) {
            $qb->andWhere('s.gateway LIKE :gateway')
               ->setParameter('gateway', $this->toLike($criteria['gateway']));
        }
        if (!empty($criteria['vrf'])) {
            $qb->andWhere('s.vrf = :vrf')
               ->setParameter('vrf', (int) $criteria['vrf']);
        }
        if (!empty($criteria['tag'])) {
            $qb->andWhere('tg.id = :tag')
               ->setParameter('tag', (int) $criteria['tag']);
        }

        return $qb;
    }

    /** @return array{subnets: Subnet[], total: int} */
    private function paginateFilterQuery(QueryBuilder $filterQb, int $page, int $perPage): array
    {
        $offset = max(0, ($page - 1) * $perPage);

        $total = (int) (clone $filterQb)
            ->select('COUNT(DISTINCT s.id)')
            ->getQuery()
            ->getSingleScalarResult();

        if ($total === 0) {
            return ['subnets' => [], 'total' => 0];
        }

        $ids = $this->idsForPage((clone $filterQb)->distinct(), $offset, $perPage);

        return ['subnets' => $this->fetchByIds($ids), 'total' => $total];
    }

    private function idsForPage(QueryBuilder $qb, int $offset, int $perPage): array
    {
        $rows = $qb->select('s.id as sid', 's.name as sname')
            ->orderBy('s.name', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'sid');
    }

    /** @return Subnet[] */
    private function fetchByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return $this->createQueryBuilder('s')
            ->leftJoin('s.vrf', 'v')->addSelect('v')
            ->leftJoin('s.tags', 'tg')->addSelect('tg')
            ->leftJoin('s.dnssecPolicy', 'dp')->addSelect('dp')
            ->where('s.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function toLike(string $value): string
    {
        return str_contains($value, '*')
            ? str_replace('*', '%', $value)
            : '%' . $value . '%';
    }

    /**
     * Returns subnet choices for form dropdowns.
     * If the saved search yields results, returns a grouped array with matches first.
     * Otherwise returns a flat ordered array of all non-container subnets.
     *
     * @return Subnet[]|array<string, Subnet[]>
     */
    public function buildGroupedChoices(?array $savedSearch): array
    {
        $all = $this->createQueryBuilder('s')
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();

        if (empty($savedSearch)) {
            return $all;
        }

        $filterIds = $this->idsForSearch($savedSearch);
        if (empty($filterIds)) {
            return $all;
        }

        $idSet    = array_flip($filterIds);
        $filtered = array_values(array_filter($all, fn(Subnet $s) => isset($idSet[$s->getId()])));

        return empty($filtered) ? $all : ['Saved search' => $filtered, 'All subnets' => $all];
    }

    private function idsForSearch(array $savedSearch): array
    {
        $criteria = array_filter(
            array_intersect_key($savedSearch, array_flip(['name', 'cidr', 'vlan', 'gateway', 'vrf', 'tag'])),
            fn($v) => $v !== ''
        );

        if (!empty($criteria)) {
            $qb = $this->buildAdvancedQb($criteria);
        } elseif (!empty($savedSearch['q'])) {
            $qb = $this->buildSearchQb($savedSearch['q']);
        } else {
            return [];
        }

        $rows = (clone $qb)
            ->select('DISTINCT s.id as sid')
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'sid');
    }

    // -------------------------------------------------------------------------
    // Tree / hierarchy helpers
    // -------------------------------------------------------------------------

    /**
     * Build a flat list of ['subnet' => Subnet, 'depth' => int] for tree display.
     * $version: 'ipv4' or 'ipv6'
     * Container subnets act as parents; parent is chosen by most-specific CIDR containment.
     * Subnets with no CIDR for the given version appear last at depth 0.
     */
    public function buildFlatHierarchy(string $version): array
    {
        $subnets   = $this->findBy([], ['name' => 'ASC']);
        $cidrMethod = $version === 'ipv4' ? 'getIpv4Cidr' : 'getIpv6Cidr';

        $subnets = array_values(array_filter($subnets, fn(Subnet $s) => $s->$cidrMethod() !== null));

        $subnetById = [];
        foreach ($subnets as $s) {
            $subnetById[$s->getId()] = $s;
        }

        // Map subnet_id => parent_id|null using CIDR containment
        $parentId = [];
        foreach ($subnets as $subnet) {
            $bestParentId  = null;
            $bestPrefixLen = -1;
            $cidr          = $subnet->$cidrMethod();

            foreach ($subnets as $candidate) {
                if (!$candidate->isContainer()) continue;
                if ($candidate->getId() === $subnet->getId()) continue;

                $candidateCidr = $candidate->$cidrMethod();
                if (!$candidateCidr) continue;

                [, $prefix] = explode('/', $candidateCidr);
                $prefixLen   = (int) $prefix;

                if ($prefixLen > $bestPrefixLen && $this->cidrContains($candidateCidr, $cidr)) {
                    $bestParentId  = $candidate->getId();
                    $bestPrefixLen = $prefixLen;
                }
            }

            $parentId[$subnet->getId()] = $bestParentId;
        }

        // Build children lists and root list
        $children = [];
        $rootIds  = [];

        foreach ($subnets as $subnet) {
            $pid = $parentId[$subnet->getId()];
            if ($pid === null) {
                $rootIds[] = $subnet->getId();
            } else {
                $children[$pid][] = $subnet->getId();
            }
        }

        $this->sortByCidr($rootIds, $subnetById, $cidrMethod, $version);
        foreach ($children as &$childList) {
            $this->sortByCidr($childList, $subnetById, $cidrMethod, $version);
        }
        unset($childList);

        $result = [];
        $this->flattenTree($rootIds, $children, $subnetById, $cidrMethod, $result, 0);

        return $result;
    }

    private function flattenTree(
        array  $ids,
        array  $children,
        array  $subnetById,
        string $cidrMethod,
        array  &$result,
        int    $depth
    ): void {
        foreach ($ids as $id) {
            $result[] = ['subnet' => $subnetById[$id], 'depth' => $depth];
            if (!empty($children[$id])) {
                $this->flattenTree($children[$id], $children, $subnetById, $cidrMethod, $result, $depth + 1);
            }
        }
    }

    private function sortByCidr(array &$ids, array $subnetById, string $cidrMethod, string $version): void
    {
        usort($ids, function (int $aId, int $bId) use ($subnetById, $cidrMethod, $version): int {
            [$aIp, $aPrefix] = explode('/', $subnetById[$aId]->$cidrMethod());
            [$bIp, $bPrefix] = explode('/', $subnetById[$bId]->$cidrMethod());
            $prefixDiff = (int) $aPrefix - (int) $bPrefix;
            if ($prefixDiff !== 0) {
                return $prefixDiff;
            }
            if ($version === 'ipv4') {
                return ip2long($aIp) <=> ip2long($bIp);
            }
            return strcmp((string) inet_pton($aIp), (string) inet_pton($bIp));
        });
    }

    private function cidrContains(string $containerCidr, string $childCidr): bool
    {
        if ($containerCidr === $childCidr) {
            return false;
        }
        $container = Factory::parseRangeString($containerCidr);
        $child     = Factory::parseRangeString($childCidr);
        if (!$container || !$child) {
            return false;
        }
        return $container->contains($child->getStartAddress())
            && $container->contains($child->getEndAddress());
    }
}
