<?php

namespace App\Service;

use App\Entity\Subnet;
use App\Repository\SubnetRepository;
use IPLib\Address\IPv4;
use IPLib\Address\IPv6;
use IPLib\Factory;
use IPLib\Range\RangeInterface;

class SubnetAllocationService
{
    public function __construct(
        private readonly SubnetRepository $subnetRepo,
    ) {}

    /**
     * Finds the first available IPv4 CIDR of $prefixLen within $container's IPv4 range, skipping
     * space already occupied by existing child subnets (of any prefix length).
     *
     * @return array{cidr: ?string, error: ?string}
     */
    public function findNextAvailableIpv4Cidr(Subnet $container, int $prefixLen): array
    {
        $containerCidr = $container->getIpv4Cidr();
        if (!$containerCidr) {
            return ['cidr' => null, 'error' => 'Container has no IPv4 CIDR to allocate from.'];
        }

        $existing = array_filter(array_map(
            fn(Subnet $s) => Factory::parseRangeString($s->getIpv4Cidr()),
            $this->subnetRepo->findV4ContainedBy($containerCidr)
        ));

        return $this->findNextAvailableCidr($containerCidr, $prefixLen, $existing);
    }

    /**
     * Finds the first available IPv6 CIDR of $prefixLen within $container's IPv6 range, skipping
     * space already occupied by existing child subnets (of any prefix length).
     *
     * @return array{cidr: ?string, error: ?string}
     */
    public function findNextAvailableIpv6Cidr(Subnet $container, int $prefixLen): array
    {
        $containerCidr = $container->getIpv6Cidr();
        if (!$containerCidr) {
            return ['cidr' => null, 'error' => 'Container has no IPv6 CIDR to allocate from.'];
        }

        $existing = array_filter(array_map(
            fn(Subnet $s) => Factory::parseRangeString($s->getIpv6Cidr()),
            $this->subnetRepo->findV6ContainedBy($containerCidr)
        ));

        return $this->findNextAvailableCidr($containerCidr, $prefixLen, $existing);
    }

    /** @param RangeInterface[] $existing */
    private function findNextAvailableCidr(string $containerCidr, int $prefixLen, array $existing): array
    {
        $range = Factory::parseRangeString($containerCidr);
        if (!$range) {
            return ['cidr' => null, 'error' => sprintf('"%s" is not a valid CIDR.', $containerCidr)];
        }

        $containerPrefix = $range->getNetworkPrefix();
        $maxPrefix       = $range->getStartAddress()::getNumberOfBits();

        if ($prefixLen <= $containerPrefix || $prefixLen > $maxPrefix) {
            return ['cidr' => null, 'error' => sprintf(
                'Prefix length /%d must be larger than the container\'s /%d and at most /%d.',
                $prefixLen, $containerPrefix, $maxPrefix
            )];
        }

        $delta = $this->blockDelta($maxPrefix, $prefixLen);

        $candidate = $range->getStartAddress();
        while ($candidate !== null && $range->contains($candidate)) {
            $candidateRange = Factory::parseRangeString($candidate->toString() . '/' . $prefixLen);
            if ($candidateRange && !$this->overlapsAny($candidateRange, $existing)) {
                return ['cidr' => $candidateRange->toString(), 'error' => null];
            }
            $candidate = $candidate->add($delta);
        }

        return ['cidr' => null, 'error' => sprintf('No available /%d block found in %s.', $prefixLen, $containerCidr)];
    }

    /**
     * Derives the IPv6 CIDR corresponding to an IPv4 subnet's position within its container, by placing
     * it at the same relative index within the container's IPv6 range. This generalizes the common manual
     * convention of hex-encoding the IPv4 subnet's variable octet into an IPv6 group: when the IPv4 prefix
     * is /24 and the IPv6 slot is 16 bits wide, the index IS that octet, placed in the low byte of the group.
     *
     * @return array{cidr: ?string, error: ?string}
     */
    public function deriveCorrespondingIpv6Cidr(Subnet $container, string $ipv4Cidr, int $v6PrefixLen): array
    {
        $v6ContainerCidr = $container->getIpv6Cidr();
        if (!$v6ContainerCidr) {
            return ['cidr' => null, 'error' => 'Container has no IPv6 CIDR to pair with.'];
        }
        $v4ContainerCidr = $container->getIpv4Cidr();
        if (!$v4ContainerCidr) {
            return ['cidr' => null, 'error' => 'Container has no IPv4 CIDR to derive the index from.'];
        }

        $v4Range          = Factory::parseRangeString($ipv4Cidr);
        $v4ContainerRange = Factory::parseRangeString($v4ContainerCidr);
        $v6ContainerRange = Factory::parseRangeString($v6ContainerCidr);
        if (!$v4Range || !$v4ContainerRange || !$v6ContainerRange) {
            return ['cidr' => null, 'error' => 'Invalid CIDR.'];
        }

        if (!$v4ContainerRange->containsRange($v4Range)) {
            return ['cidr' => null, 'error' => sprintf('%s is not contained within the container\'s IPv4 CIDR %s.', $ipv4Cidr, $v4ContainerCidr)];
        }

        $v4Prefix          = $v4Range->getNetworkPrefix();
        $v4ContainerPrefix = $v4ContainerRange->getNetworkPrefix();
        $v6ContainerPrefix = $v6ContainerRange->getNetworkPrefix();

        if ($v6PrefixLen <= $v6ContainerPrefix || $v6PrefixLen > 128) {
            return ['cidr' => null, 'error' => sprintf(
                'IPv6 prefix length /%d must be larger than the container\'s /%d and at most /128.',
                $v6PrefixLen, $v6ContainerPrefix
            )];
        }

        $indexBits     = $v4Prefix - $v4ContainerPrefix;
        $availableBits = $v6PrefixLen - $v6ContainerPrefix;

        if ($availableBits < $indexBits) {
            return ['cidr' => null, 'error' => sprintf(
                'The IPv6 container is too small to represent this subnet\'s position: a /%d IPv4 subnet needs %d index bit(s), but /%d leaves only %d bit(s) before /%d.',
                $v4Prefix, $indexBits, $v6ContainerPrefix, $availableBits, $v6PrefixLen
            )];
        }

        $v4NetworkBits = $v4Range->getStartAddress()->getBits();
        $indexBinary   = $indexBits > 0 ? substr($v4NetworkBits, $v4ContainerPrefix, $indexBits) : '';

        $v6ContainerBits = $v6ContainerRange->getStartAddress()->getBits();
        $prefixBits      = substr($v6ContainerBits, 0, $v6ContainerPrefix);
        $paddedIndex     = str_pad($indexBinary, $availableBits, '0', STR_PAD_LEFT);
        $hostBits        = str_repeat('0', 128 - $v6ContainerPrefix - $availableBits);

        $words   = array_map('bindec', str_split($prefixBits . $paddedIndex . $hostBits, 16));
        $address = IPv6::fromWords($words);

        $candidateCidr  = $address->toString() . '/' . $v6PrefixLen;
        $candidateRange = Factory::parseRangeString($candidateCidr);

        $existing = array_filter(array_map(
            fn(Subnet $s) => Factory::parseRangeString($s->getIpv6Cidr()),
            $this->subnetRepo->findV6ContainedBy($v6ContainerCidr)
        ));

        foreach ($existing as $other) {
            if ($this->overlaps($candidateRange, $other)) {
                return ['cidr' => null, 'error' => sprintf('Computed IPv6 CIDR %s overlaps with an existing subnet.', $candidateCidr)];
            }
        }

        return ['cidr' => $candidateCidr, 'error' => null];
    }

    private function blockDelta(int $maxPrefix, int $prefixLen): IPv4|IPv6
    {
        $one = $maxPrefix === 32
            ? IPv4::fromBytes([0, 0, 0, 1])
            : IPv6::fromBytes([0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1]);

        return $one->shift($prefixLen - $maxPrefix);
    }

    /** @param RangeInterface[] $existing */
    private function overlapsAny(RangeInterface $candidate, array $existing): bool
    {
        foreach ($existing as $other) {
            if ($this->overlaps($candidate, $other)) {
                return true;
            }
        }
        return false;
    }

    private function overlaps(RangeInterface $a, RangeInterface $b): bool
    {
        return $a->contains($b->getStartAddress()) || $b->contains($a->getStartAddress());
    }
}
