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
                if ($hostname = $iface->getPrimaryName()) {
                    $res['hostname'] = rtrim($hostname, '.') . '.';
                }
                $reservations[] = $res;
            }
            if ($reservations) {
                $block['reservations'] = $reservations;
            }

            if ($subnet->getDdnsQualifyingSuffix()) {
                $block['ddns-send-updates']        = true;
                $block['ddns-update-on-renew']     = true;
                $block['ddns-qualifying-suffix']   = rtrim($subnet->getDdnsQualifyingSuffix(), '.') . '.';
                $block['ddns-replace-client-name'] = 'always';
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
                if (!$iface->getIpv6Address() || $iface->getMacAddress() === '00:00:00:00:00:00') {
                    continue;
                }
                $res = [
                    'hw-address'   => $iface->getMacAddress(),
                    'ip-addresses' => [$iface->getIpv6Address()->getAddress()],
                ];
                if ($hostname = $iface->getPrimaryName()) {
                    $res['hostname'] = rtrim($hostname, '.') . '.';
                }
                $reservations[] = $res;
            }
            if ($reservations) {
                $block['reservations'] = $reservations;
            }

            if ($subnet->getDdnsQualifyingSuffix()) {
                $block['ddns-send-updates']        = true;
                $block['ddns-update-on-renew']     = true;
                $block['ddns-qualifying-suffix']   = rtrim($subnet->getDdnsQualifyingSuffix(), '.') . '.';
                $block['ddns-replace-client-name'] = 'always';
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
                if ($iface->isDeleted() || $iface->getIpv6Address() || $iface->getMacAddress() === '00:00:00:00:00:00') {
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
