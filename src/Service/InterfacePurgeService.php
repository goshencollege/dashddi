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
            [$ipv4, $ipv6] = $this->resolveLeaseAddresses($interface);
            $this->bus->dispatch(new PurgeInterfaceLeaseMessage($ipv4, $ipv6));
            $this->em->remove($interface);
            $count++;
        }
        if ($count > 0) {
            $this->em->flush();
        }

        return $count;
    }

    /**
     * An interface's lease may be a static reservation (ipAddress/ipv6Address) or a
     * plain dynamic pool address Kea assigned it, tracked only via lastDhcpIp from
     * the lease webhook. Fall back to lastDhcpIp so a purely dynamic lease still
     * gets cleared, since that's the common case during pool exhaustion.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveLeaseAddresses(NetworkInterface $interface): array
    {
        $ipv4 = $interface->getIpAddress()?->getAddress();
        $ipv6 = $interface->getIpv6Address()?->getAddress();

        if ($ipv4 === null && $ipv6 === null && $interface->getLastDhcpIp() !== null) {
            $lastDhcp = $interface->getLastDhcpIp();
            if (str_contains($lastDhcp, ':')) {
                $ipv6 = $lastDhcp;
            } else {
                $ipv4 = $lastDhcp;
            }
        }

        return [$ipv4, $ipv6];
    }
}
