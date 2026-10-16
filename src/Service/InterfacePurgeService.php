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
            $this->bus->dispatch(new PurgeInterfaceLeaseMessage(
                $interface->getIpAddress()?->getAddress(),
                $interface->getIpv6Address()?->getAddress(),
            ));
            $this->em->remove($interface);
            $count++;
        }
        if ($count > 0) {
            $this->em->flush();
        }

        return $count;
    }
}
