<?php

namespace App\Repository;

use App\Entity\Subnet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
