<?php

namespace App\Service;

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
                    $res['hostname'] = $hostname;
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
                $block['ddns-replace-client-name'] = 'when-not-present';
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
                    'hw-address' => $iface->getMacAddress(),
                    'ip-addresses' => [$iface->getIpv6Address()->getAddress()],
                ];
                if ($hostname = $iface->getPrimaryName()) {
                    $res['hostname'] = $hostname;
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
                $block['ddns-replace-client-name'] = 'when-not-present';
            }

            $subnet6[] = $block;
        }

        return $subnet6;
    }
}
