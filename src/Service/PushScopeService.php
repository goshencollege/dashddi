<?php

namespace App\Service;

use App\Entity\Domain;
use App\Entity\DomainRecord;
use App\Entity\InterfaceName;
use App\Entity\IpAddress;
use App\Entity\Ipv6Address;
use App\Entity\NetworkInterface;
use App\Entity\Subnet;
use App\Entity\RadiusClient;
use App\Repository\DhcpServerRepository;
use App\Repository\DnsServerRepository;
use App\Repository\RadiusServerRepository;

class PushScopeService
{
    public function __construct(
        private readonly DnsServerRepository    $dnsRepo,
        private readonly DhcpServerRepository   $dhcpRepo,
        private readonly RadiusServerRepository $radiusRepo,
    ) {}

    /** @return int[] DNS server IDs whose zones are affected by this entity change */
    public function dnsServerIdsFor(object $entity): array
    {
        $viewIds = $this->viewIdsFor($entity);
        if (empty($viewIds)) {
            return [];
        }

        return $this->dnsRepo->findIdsByViewIds($viewIds);
    }

    public function affectsDhcp(object $entity): bool
    {
        return $entity instanceof NetworkInterface
            || $entity instanceof IpAddress
            || $entity instanceof Ipv6Address
            || $entity instanceof Subnet;
    }

    /** @return int[] */
    public function allDhcpServerIds(): array
    {
        return $this->dhcpRepo->findAllIds();
    }

    public function affectsRadius(object $entity): bool
    {
        return $entity instanceof RadiusClient;
    }

    /** @return int[] */
    public function allRadiusServerIds(): array
    {
        return $this->radiusRepo->findAllIds();
    }

    private function viewIdsFor(object $entity): array
    {
        return match(true) {
            $entity instanceof InterfaceName  => $this->collectViewIds($entity->getViews()),
            $entity instanceof NetworkInterface => $this->viewIdsForNetworkInterface($entity),
            $entity instanceof IpAddress,
            $entity instanceof Ipv6Address    => $this->collectViewIds($entity->getSubnet()?->getViews() ?? []),
            $entity instanceof DomainRecord   => $this->collectViewIds($entity->getViews()),
            $entity instanceof Domain         => $this->collectViewIds($entity->getViews()),
            $entity instanceof Subnet         => $this->collectViewIds($entity->getViews()),
            default                           => [],
        };
    }

    private function collectViewIds(iterable $views): array
    {
        $ids = [];
        foreach ($views as $v) {
            $ids[] = $v->getId();
        }

        return array_unique($ids);
    }

    private function viewIdsForNetworkInterface(NetworkInterface $iface): array
    {
        $ids = [];
        foreach ($iface->getNames() as $name) {
            foreach ($name->getViews() as $v) {
                $ids[$v->getId()] = true;
            }
        }
        if ($subnet = $iface->getSubnet()) {
            foreach ($subnet->getViews() as $v) {
                $ids[$v->getId()] = true;
            }
        }

        return array_keys($ids);
    }
}
