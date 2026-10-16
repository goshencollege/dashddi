<?php

namespace App\Service;

use App\Entity\NetworkInterface;
use App\Message\PurgeInterfaceLeaseMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Single choke point for hard-deleting NetworkInterface rows, so every purge path
 * (scheduled retention purge, on-demand "Purge Now") also queues DHCP lease cleanup.
 */
class InterfacePurgeService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {}

    /**
     * @param iterable<NetworkInterface> $interfaces
     */
    public function purgeMany(iterable $interfaces): int
    {
        $count = 0;
        foreach ($interfaces as $interface) {
            [$ipv4Addresses, $ipv6Addresses] = $this->resolveLeaseAddresses($interface);
            $this->bus->dispatch(new PurgeInterfaceLeaseMessage($ipv4Addresses, $ipv6Addresses, $interface->getMacAddress()));
            $this->em->remove($interface);
            $count++;
        }
        if ($count > 0) {
            $this->em->flush();
        }

        return $count;
    }

    /**
     * An interface's lease could be sitting at its static reservation
     * (ipAddress/ipv6Address), at lastDhcpIp (the last address Kea's lease webhook
     * reported — the only record for a purely dynamic pool address with no
     * reservation), or both at once (e.g. it had a dynamic lease before a static
     * reservation was added and hasn't renewed onto the new address yet). Collect
     * every distinct candidate so the purge can attempt to clear all of them.
     *
     * @return array{0: string[], 1: string[]}
     */
    private function resolveLeaseAddresses(NetworkInterface $interface): array
    {
        $ipv4 = $interface->getIpAddress()?->getAddress();
        $ipv6 = $interface->getIpv6Address()?->getAddress();

        $lastDhcp = $interface->getLastDhcpIp();
        if ($lastDhcp !== null) {
            if (str_contains($lastDhcp, ':')) {
                $ipv6Addresses = array_unique(array_filter([$ipv6, $lastDhcp]));
                $ipv4Addresses = array_filter([$ipv4]);
            } else {
                $ipv4Addresses = array_unique(array_filter([$ipv4, $lastDhcp]));
                $ipv6Addresses = array_filter([$ipv6]);
            }
        } else {
            $ipv4Addresses = array_filter([$ipv4]);
            $ipv6Addresses = array_filter([$ipv6]);
        }

        return [array_values($ipv4Addresses), array_values($ipv6Addresses)];
    }
}
