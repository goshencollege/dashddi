<?php

namespace App\Service;

use App\Entity\IpAddress;
use App\Entity\Ipv6Address;
use App\Entity\NetworkInterface;
use App\Entity\Subnet;
use App\Repository\IpAddressRepository;
use App\Repository\Ipv6AddressRepository;
use Doctrine\ORM\EntityManagerInterface;
use IPLib\Factory;

class IpAddressManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IpAddressRepository $ipRepo,
        private readonly Ipv6AddressRepository $ipv6Repo,
    ) {}

    /** Returns up to $limit available IPv4 addresses in the subnet, excluding first (network) and last (broadcast). */
    public function getAvailableIpv4(Subnet $subnet, int $limit = 100): array
    {
        if (!$subnet->getIpv4Cidr()) {
            return [];
        }

        $range = Factory::parseRangeString($subnet->getIpv4Cidr());
        if (!$range) {
            return [];
        }

        $allocated = array_flip($this->ipRepo->findAllocatedAddressesForSubnet($subnet->getId()));
        $available = [];

        $start = $range->getStartAddress();
        $end   = $range->getEndAddress();

        // Skip network address
        $current = $start->getNextAddress();

        while ($current !== null && count($available) < $limit) {
            $str = (string) $current;
            // Stop before broadcast
            if ($current->toString() === $end->toString()) {
                break;
            }
            if (!isset($allocated[$str])) {
                $available[] = $str;
            }
            $current = $current->getNextAddress();
        }

        return $available;
    }

    /** Returns up to $limit available IPv6 addresses in the subnet. */
    public function getAvailableIpv6(Subnet $subnet, int $limit = 50): array
    {
        if (!$subnet->getIpv6Cidr()) {
            return [];
        }

        $range = Factory::parseRangeString($subnet->getIpv6Cidr());
        if (!$range) {
            return [];
        }

        $allocated = array_flip($this->ipv6Repo->findAllocatedAddressesForSubnet($subnet->getId()));
        $available = [];

        $current = $range->getStartAddress()->getNextAddress();

        while ($current !== null && count($available) < $limit) {
            if (!$range->contains($current)) {
                break;
            }
            $str = $current->toString();
            if (!isset($allocated[$str])) {
                $available[] = $str;
            }
            $current = $current->getNextAddress();
        }

        return $available;
    }

    public function findNextAvailableIpv4(Subnet $subnet): ?string
    {
        $available = $this->getAvailableIpv4($subnet, 1);
        return $available[0] ?? null;
    }

    public function findNextAvailableIpv6(Subnet $subnet, ?string $macAddress = null): ?string
    {
        if ($macAddress && $subnet->getIpv6Cidr()) {
            $eui64 = $this->macToEui64($macAddress, $subnet->getIpv6Cidr());
            if ($eui64) {
                $allocated = array_flip($this->ipv6Repo->findAllocatedAddressesForSubnet($subnet->getId()));
                if (!isset($allocated[$eui64])) {
                    return $eui64;
                }
            }
        }

        $available = $this->getAvailableIpv6($subnet, 1);
        return $available[0] ?? null;
    }

    public function assignIpv4(NetworkInterface $interface, string $address): IpAddress
    {
        $ip = new IpAddress();
        $ip->setAddress($address);
        $ip->setSubnet($interface->getSubnet());

        $interface->setIpAddress($ip);
        $this->em->persist($ip);

        return $ip;
    }

    public function assignIpv6(NetworkInterface $interface, string $address): Ipv6Address
    {
        $ip = new Ipv6Address();
        $ip->setAddress($address);
        $ip->setSubnet($interface->getSubnet());

        $interface->setIpv6Address($ip);
        $this->em->persist($ip);

        return $ip;
    }

    public function releaseIpv4(NetworkInterface $interface): void
    {
        $ip = $interface->getIpAddress();
        if ($ip) {
            $interface->setIpAddress(null);
            $this->em->remove($ip);
        }
    }

    public function releaseIpv6(NetworkInterface $interface): void
    {
        $ip = $interface->getIpv6Address();
        if ($ip) {
            $interface->setIpv6Address(null);
            $this->em->remove($ip);
        }
    }

    /** Converts a MAC address to an EUI-64 IPv6 interface identifier appended to the given /64 prefix. */
    private function macToEui64(string $mac, string $cidr): ?string
    {
        $mac = strtolower(preg_replace('/[^0-9a-f]/i', '', $mac));
        if (strlen($mac) !== 12) {
            return null;
        }

        $range = Factory::parseRangeString($cidr);
        if (!$range) {
            return null;
        }

        $prefix = $range->getStartAddress()->toString();
        // Remove trailing zeros to get prefix portion
        $parts = explode(':', $prefix);
        $prefixParts = array_slice($parts, 0, 4);

        // Build EUI-64 interface ID
        $bytes = str_split($mac, 2);
        $bytes[0] = dechex(hexdec($bytes[0]) ^ 0x02); // flip universal/local bit
        $eui64Parts = [
            $bytes[0] . $bytes[1],
            $bytes[2] . 'ff',
            'fe' . $bytes[3],
            $bytes[4] . $bytes[5],
        ];

        $full = implode(':', array_merge($prefixParts, $eui64Parts));

        $parsed = Factory::parseAddressString($full);
        return $parsed ? $parsed->toString() : null;
    }
}
