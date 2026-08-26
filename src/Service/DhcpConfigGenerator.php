<?php

namespace App\Service;

use App\Entity\NetworkInterface;
use App\Enum\BlockType;
use App\Repository\SubnetRepository;

class DhcpConfigGenerator
{
    public function __construct(
        private readonly SubnetRepository $subnetRepository,
    ) {}

    public function generateDhcp4Config(): array
    {
        $subnet4 = [];

        foreach ($this->subnetRepository->findAll() as $subnet) {
            if ($subnet->isContainer() || !$subnet->getIpv4Cidr()) {
                continue;
            }

            $block = [
                'id' => $subnet->getId(),
                'subnet' => $subnet->getIpv4Cidr(),
            ];

            $pools = [];
            foreach ($subnet->getAddressBlocks() as $ab) {
                if ($ab->getType() === BlockType::Dynamic && !str_contains($ab->getStartIp(), ':')) {
                    $pools[] = ['pool' => $ab->getStartIp() . ' - ' . $ab->getEndIp()];
                }
            }
            if ($pools) {
                $block['pools'] = $pools;
            }

            if ($subnet->getGateway()) {
                $block['option-data'] = [
                    ['name' => 'routers', 'data' => $subnet->getGateway()],
                ];
            }

            $reservations = [];
            foreach ($subnet->getInterfaces() as $iface) {
                if ($iface->isDeleted() || !$iface->getIpAddress() || $iface->getMacAddress() === '00:00:00:00:00:00') {
                    continue;
                }
                $res = [
                    'hw-address' => $iface->getMacAddress(),
                    'ip-address' => $iface->getIpAddress()->getAddress(),
                ];
                $this->applyHostnameAndDdns($res, $iface, (bool) $subnet->getDdnsQualifyingSuffix());
                $reservations[] = $res;
            }
            if ($reservations) {
                $block['reservations'] = $reservations;
            }

            if ($subnet->getDdnsQualifyingSuffix()) {
                $block['ddns-send-updates']        = true;
                $block['ddns-update-on-renew']     = true;
                $block['ddns-replace-client-name'] = 'never';
            }

            $subnet4[] = $block;
        }

        return $subnet4;
    }

    public function generateDhcp6Config(): array
    {
        $subnet6 = [];

        foreach ($this->subnetRepository->findAll() as $subnet) {
            if ($subnet->isContainer() || !$subnet->getIpv6Cidr()) {
                continue;
            }

            $block = [
                'id' => $subnet->getId(),
                'subnet' => $subnet->getIpv6Cidr(),
            ];

            if ($subnet->getDhcpv6Interface()) {
                $block['interface'] = $subnet->getDhcpv6Interface();
            }

            $pools = [];
            foreach ($subnet->getAddressBlocks() as $ab) {
                if ($ab->getType() === BlockType::Dynamic && str_contains($ab->getStartIp(), ':')) {
                    $pools[] = ['pool' => $ab->getStartIp() . ' - ' . $ab->getEndIp()];
                }
            }
            if ($pools) {
                $block['pools'] = $pools;
            }

            $reservations = [];
            foreach ($subnet->getInterfaces() as $iface) {
                $duid = $iface->getHost()?->getDuid();
                $mac  = $iface->getMacAddress();
                if ($iface->isDeleted() || !$iface->getIpv6Address() || (!$duid && $mac === '00:00:00:00:00:00')) {
                    continue;
                }
                $res = $duid
                    ? ['duid' => $duid, 'ip-addresses' => [$iface->getIpv6Address()->getAddress()]]
                    : ['hw-address' => $mac, 'ip-addresses' => [$iface->getIpv6Address()->getAddress()]];
                $this->applyHostnameAndDdns($res, $iface, (bool) $subnet->getDdnsQualifyingSuffix());
                $reservations[] = $res;
            }
            if ($reservations) {
                $block['reservations'] = $reservations;
            }

            if ($subnet->getDdnsQualifyingSuffix()) {
                $block['ddns-send-updates']        = true;
                $block['ddns-update-on-renew']     = true;
                $block['ddns-replace-client-name'] = 'never';
            }

            $subnet6[] = $block;
        }

        return $subnet6;
    }

    public function generateGlobalReservations4Config(): array
    {
        $reservations = [];

        foreach ($this->subnetRepository->findAll() as $subnet) {
            foreach ($subnet->getInterfaces() as $iface) {
                if ($iface->isDeleted() || $iface->getIpAddress() || $iface->getMacAddress() === '00:00:00:00:00:00') {
                    continue;
                }
                if ($label = $this->findAnyDdnsLabel($iface)) {
                    $reservations[] = [
                        'hw-address' => $iface->getMacAddress(),
                        'hostname'   => $label,
                    ];
                }
            }
        }

        return $reservations;
    }

    public function generateGlobalReservations6Config(): array
    {
        $reservations = [];

        foreach ($this->subnetRepository->findAll() as $subnet) {
            foreach ($subnet->getInterfaces() as $iface) {
                $duid = $iface->getHost()?->getDuid();
                $mac  = $iface->getMacAddress();
                if ($iface->isDeleted() || $iface->getIpv6Address() || (!$duid && $mac === '00:00:00:00:00:00')) {
                    continue;
                }
                if ($label = $this->findAnyDdnsLabel($iface)) {
                    $reservations[] = $duid
                        ? ['duid' => $duid, 'hostname' => $label]
                        : ['hw-address' => $mac, 'hostname' => $label];
                }
            }
        }

        return $reservations;
    }

    /**
     * Sets the reservation hostname from the interface's primary DomainRecord. If the subnet
     * has DDNS enabled but this record's domain doesn't, the reservation is assigned to the
     * "SKIP_DDNS" client class (recognized by the libdhcp_ddns_tuning.so hook, if loaded) so
     * Kea never sends a DDNS update for it — a host with a static FQDN outside the dynamic
     * domain must not have its DNS records overwritten by DHCP DDNS.
     */
    private function applyHostnameAndDdns(array &$res, NetworkInterface $iface, bool $subnetDdnsEnabled): void
    {
        $record = $iface->getPrimaryDomainRecord();
        if (!$record) {
            return;
        }

        $res['hostname'] = rtrim($record->getFullyQualifiedHostname(), '.') . '.';
        if ($subnetDdnsEnabled && !$record->getDomain()?->isDdnsEnabled()) {
            $res['client-classes'] = ['SKIP_DDNS'];
        }
    }

    private function findAnyDdnsLabel(NetworkInterface $iface): ?string
    {
        foreach ($iface->getDomainRecords() as $record) {
            if ($record->getDomain()?->isDdnsEnabled()) {
                return $record->getHostname();
            }
        }
        return null;
    }
}
