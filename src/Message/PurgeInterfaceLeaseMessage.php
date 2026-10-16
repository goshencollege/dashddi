<?php

namespace App\Message;

final class PurgeInterfaceLeaseMessage
{
    /**
     * @param string[] $ipv4Addresses
     * @param string[] $ipv6Addresses
     */
    public function __construct(
        public readonly array $ipv4Addresses,
        public readonly array $ipv6Addresses,
        public readonly ?string $macAddress,
    ) {}
}
