<?php

namespace App\Service;

use App\Entity\Domain;
use App\Entity\DomainRecord;
use App\Entity\IpAddress;
use App\Entity\Ipv6Address;
use App\Entity\NetworkInterface;
use App\Entity\Subnet;
use App\Repository\ClearpassServerRepository;
use App\Repository\DhcpServerRepository;
use App\Repository\DnsServerRepository;
use App\Repository\NetworkInterfaceRepository;

class PushScopeService
{
    public function __construct(
        private readonly ClearpassServerRepository  $clearpassRepo,
        private readonly DnsServerRepository        $dnsRepo,
        private readonly DhcpServerRepository       $dhcpRepo,
        private readonly NetworkInterfaceRepository $ifaceRepo,
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

    /**
     * Returns the MAC addresses affected by a change to this entity for ClearPass.
     * Returns an empty array if the entity doesn't affect ClearPass endpoints.
     *
     * @return string[]
     */
    public function clearpassMacsFor(object $entity): array
    {
        if ($entity instanceof NetworkInterface) {
            $mac = $entity->getMacAddress();
            return ($mac !== '' && $mac !== '00:00:00:00:00:00') ? [$mac] : [];
        }

        if ($entity instanceof IpAddress) {
            $mac = $this->ifaceRepo->findMacByIpAddress($entity);
            return $mac !== null ? [$mac] : [];
        }

        if ($entity instanceof Ipv6Address) {
            $mac = $this->ifaceRepo->findMacByIpv6Address($entity);
            return $mac !== null ? [$mac] : [];
        }

        return [];
    }

    /** @return int[] */
    public function allClearpassServerIds(): array
    {
        return $this->clearpassRepo->findAllIds();
    }


    private function viewIdsFor(object $entity): array
    {
        return match(true) {
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
        foreach ($iface->getDomainRecords() as $record) {
            foreach ($record->getViews() as $v) {
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
