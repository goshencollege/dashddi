<?php

namespace App\Service;

use App\Entity\IpAddress;
use App\Entity\Ipv6Address;
use App\Entity\NetworkInterface;
use App\Entity\Subnet;
use App\Repository\AddressBlockRepository;
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
        private readonly AddressBlockRepository $blockRepo,
    ) {}

    /** Returns up to $limit available IPv4 addresses, restricted to Fixed blocks when defined. */
    public function getAvailableIpv4(Subnet $subnet, int $limit = 100): array
    {
        if (!$subnet->getIpv4Cidr()) {
            return [];
        }

        $allocated   = array_flip($this->ipRepo->findAllocatedAddressesForSubnet($subnet->getId()));
        $fixedBlocks = array_filter(
            $this->blockRepo->findFixedBySubnet($subnet->getId()),
            fn($b) => !str_contains($b->getStartIp(), ':')
        );

        if (!empty($fixedBlocks)) {
            return $this->availableInBlocks($fixedBlocks, $allocated, $limit);
        }

        // No blocks defined — fall back to full subnet range
        $range = Factory::parseRangeString($subnet->getIpv4Cidr());
        if (!$range) {
            return [];
        }

        $available = [];
        $end       = $range->getEndAddress();
        $current   = $range->getStartAddress()->getNextAddress(); // skip network address

        while ($current !== null && count($available) < $limit) {
            if ($current->toString() === $end->toString()) { // stop before broadcast
                break;
            }
            if (!isset($allocated[$current->toString()])) {
                $available[] = $current->toString();
            }
            $current = $current->getNextAddress();
        }

        return $available;
    }

    /** Returns up to $limit available IPv6 addresses, restricted to Fixed blocks when defined. */
    public function getAvailableIpv6(Subnet $subnet, int $limit = 50): array
    {
        if (!$subnet->getIpv6Cidr()) {
            return [];
        }

        $allocated   = array_flip($this->ipv6Repo->findAllocatedAddressesForSubnet($subnet->getId()));
        $fixedBlocks = array_filter(
            $this->blockRepo->findFixedBySubnet($subnet->getId()),
            fn($b) => str_contains($b->getStartIp(), ':')
        );

        if (!empty($fixedBlocks)) {
            return $this->availableInBlocks($fixedBlocks, $allocated, $limit);
        }

        // No blocks defined — fall back to full subnet range
        $range = Factory::parseRangeString($subnet->getIpv6Cidr());
        if (!$range) {
            return [];
        }

        $available = [];
        $current   = $range->getStartAddress()->getNextAddress();

        while ($current !== null && count($available) < $limit) {
            if (!$range->contains($current)) {
                break;
            }
            if (!isset($allocated[$current->toString()])) {
                $available[] = $current->toString();
            }
            $current = $current->getNextAddress();
        }

        return $available;
    }

    /** Iterates through a set of AddressBlocks and returns unallocated addresses up to $limit. */
    private function availableInBlocks(array $blocks, array $allocated, int $limit): array
    {
        $available = [];

        foreach ($blocks as $block) {
            $start = Factory::parseAddressString($block->getStartIp());
            $end   = Factory::parseAddressString($block->getEndIp());
            if (!$start || !$end) {
                continue;
            }

            $current = $start;
            while ($current !== null && count($available) < $limit) {
                $str = $current->toString();
                if (!isset($allocated[$str])) {
                    $available[] = $str;
                }
                if ($str === $end->toString()) {
                    break;
                }
                $current = $current->getNextAddress();
            }

            if (count($available) >= $limit) {
                break;
            }
        }

        return $available;
    }

    public function findNextAvailableIpv4(Subnet $subnet): ?string
    {
        $available = $this->getAvailableIpv4($subnet, 1);
        return $available[0] ?? null;
    }

    /**
     * Derives an IPv6 address from the last octet of $ipv4, placed as the last byte of the
     * subnet's network address. Returns null if the result is already allocated or outside
     * any Fixed block defined on the subnet.
     */
    public function findIpv6FromIpv4(Subnet $subnet, string $ipv4): ?string
    {
        if (!$subnet->getIpv6Cidr()) {
            return null;
        }

        $octets = explode('.', $ipv4);
        if (count($octets) !== 4) {
            return null;
        }
        $lastOctet = (int) $octets[3];

        $range = Factory::parseRangeString($subnet->getIpv6Cidr());
        if (!$range) {
            return null;
        }

        $raw = inet_pton($range->getStartAddress()->toString());
        if ($raw === false || strlen($raw) !== 16) {
            return null;
        }

        $raw[15] = chr($lastOctet);
        $ipv6Str = inet_ntop($raw);
        if ($ipv6Str === false) {
            return null;
        }

        $parsed = Factory::parseAddressString($ipv6Str);
        if (!$parsed) {
            return null;
        }
        $normalized = $parsed->toString();

        $fixedBlocks = array_filter(
            $this->blockRepo->findFixedBySubnet($subnet->getId()),
            fn($b) => str_contains($b->getStartIp(), ':')
        );
        if (!empty($fixedBlocks) && !$this->isInBlocks($normalized, $fixedBlocks)) {
            return null;
        }

        $allocated = array_flip($this->ipv6Repo->findAllocatedAddressesForSubnet($subnet->getId()));
        if (isset($allocated[$normalized])) {
            return null;
        }

        return $normalized;
    }

    public function findNextAvailableIpv6(Subnet $subnet, ?string $macAddress = null): ?string
    {
        if ($macAddress && $subnet->getIpv6Cidr()) {
            $eui64 = $this->macToEui64($macAddress, $subnet->getIpv6Cidr());
            if ($eui64) {
                $allocated   = array_flip($this->ipv6Repo->findAllocatedAddressesForSubnet($subnet->getId()));
                $fixedBlocks = array_filter(
                    $this->blockRepo->findFixedBySubnet($subnet->getId()),
                    fn($b) => str_contains($b->getStartIp(), ':')
                );

                $inFixedBlock = empty($fixedBlocks) || $this->isInBlocks($eui64, $fixedBlocks);

                if (!isset($allocated[$eui64]) && $inFixedBlock) {
                    return $eui64;
                }
            }
        }

        $available = $this->getAvailableIpv6($subnet, 1);
        return $available[0] ?? null;
    }

    private function isInBlocks(string $ip, array $blocks): bool
    {
        $addr = Factory::parseAddressString($ip);
        if (!$addr) {
            return false;
        }

        foreach ($blocks as $block) {
            $start = Factory::parseAddressString($block->getStartIp());
            $end   = Factory::parseAddressString($block->getEndIp());
            if (!$start || !$end) {
                continue;
            }
            if ($addr->getComparableString() >= $start->getComparableString()
                && $addr->getComparableString() <= $end->getComparableString()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validates a manually-specified IPv4 address. Returns an error string or null.
     * Passes if the address belongs to $currentInterface (edit keeping same IP).
     */
    public function validateSpecifiedIpv4(string $ip, Subnet $subnet, ?NetworkInterface $currentInterface = null): ?string
    {
        $parsed = Factory::parseAddressString($ip);
        if (!$parsed || $parsed->getAddressType() !== 4) {
            return sprintf('"%s" is not a valid IPv4 address.', $ip);
        }

        $normalized    = $parsed->toString();
        $allowedBlocks = array_filter(
            $this->blockRepo->findFixedOrReservedBySubnet($subnet->getId()),
            fn($b) => !str_contains($b->getStartIp(), ':')
        );

        if (!empty($allowedBlocks)) {
            if (!$this->isInBlocks($normalized, $allowedBlocks)) {
                return sprintf('"%s" does not fall within any Fixed or Reserved block in this subnet.', $normalized);
            }
        } else {
            $range = Factory::parseRangeString($subnet->getIpv4Cidr() ?? '');
            if (!$range || !$range->contains($parsed)) {
                return sprintf('"%s" is not within the subnet CIDR %s.', $normalized, $subnet->getIpv4Cidr());
            }
        }

        $existing = $this->ipRepo->findOneBy(['address' => $normalized]);
        if ($existing && $existing->getId() !== $currentInterface?->getIpAddress()?->getId()) {
            return sprintf('"%s" is already assigned to another interface.', $normalized);
        }

        return null;
    }

    /**
     * Validates a manually-specified IPv6 address. Returns an error string or null.
     * Passes if the address belongs to $currentInterface (edit keeping same IP).
     */
    public function validateSpecifiedIpv6(string $ip, Subnet $subnet, ?NetworkInterface $currentInterface = null): ?string
    {
        $parsed = Factory::parseAddressString($ip);
        if (!$parsed || $parsed->getAddressType() !== 6) {
            return sprintf('"%s" is not a valid IPv6 address.', $ip);
        }

        $normalized    = $parsed->toString();
        $allowedBlocks = array_filter(
            $this->blockRepo->findFixedOrReservedBySubnet($subnet->getId()),
            fn($b) => str_contains($b->getStartIp(), ':')
        );

        if (!empty($allowedBlocks)) {
            if (!$this->isInBlocks($normalized, $allowedBlocks)) {
                return sprintf('"%s" does not fall within any Fixed or Reserved block in this subnet.', $normalized);
            }
        } else {
            $range = Factory::parseRangeString($subnet->getIpv6Cidr() ?? '');
            if (!$range || !$range->contains($parsed)) {
                return sprintf('"%s" is not within the subnet CIDR %s.', $normalized, $subnet->getIpv6Cidr());
            }
        }

        $existing = $this->ipv6Repo->findOneBy(['address' => $normalized]);
        if ($existing && $existing->getId() !== $currentInterface?->getIpv6Address()?->getId()) {
            return sprintf('"%s" is already assigned to another interface.', $normalized);
        }

        return null;
    }

    public function isIpv4ValidInSubnet(NetworkInterface $interface, Subnet $subnet): bool
    {
        $ipAddress = $interface->getIpAddress();
        if (!$ipAddress || !$subnet->getIpv4Cidr()) {
            return false;
        }
        $parsed = Factory::parseAddressString($ipAddress->getAddress());
        $range  = Factory::parseRangeString($subnet->getIpv4Cidr());
        return $parsed !== null && $range !== null && $range->contains($parsed);
    }

    public function isIpv6ValidInSubnet(NetworkInterface $interface, Subnet $subnet): bool
    {
        $ipv6Address = $interface->getIpv6Address();
        if (!$ipv6Address || !$subnet->getIpv6Cidr()) {
            return false;
        }
        $parsed = Factory::parseAddressString($ipv6Address->getAddress());
        $range  = Factory::parseRangeString($subnet->getIpv6Cidr());
        return $parsed !== null && $range !== null && $range->contains($parsed);
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
