<?php

namespace App\Tests\Unit\Service;

use App\Entity\IpAddress;
use App\Entity\Ipv6Address;
use App\Entity\NetworkInterface;
use App\Message\PurgeInterfaceLeaseMessage;
use App\Service\InterfacePurgeService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class InterfacePurgeServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private MessageBusInterface&MockObject $bus;
    private InterfacePurgeService $service;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->bus     = $this->createMock(MessageBusInterface::class);
        $this->service = new InterfacePurgeService($this->em, $this->bus);
    }

    public function testPurgeManyDispatchesLeaseMessageWithBothAddressesAndRemovesInterface(): void
    {
        $iface = (new NetworkInterface())
            ->setIpAddress((new IpAddress())->setAddress('10.0.0.5'))
            ->setIpv6Address((new Ipv6Address())->setAddress('fd00::5'));

        $this->bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (PurgeInterfaceLeaseMessage $msg) {
                return $msg->ipv4 === '10.0.0.5' && $msg->ipv6 === 'fd00::5';
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->em->expects($this->once())->method('remove')->with($iface);
        $this->em->expects($this->once())->method('flush');

        $count = $this->service->purgeMany([$iface]);

        $this->assertSame(1, $count);
    }

    public function testPurgeManyDispatchesNullForMissingAddressFamily(): void
    {
        $iface = (new NetworkInterface())->setIpAddress((new IpAddress())->setAddress('10.0.0.9'));

        $this->bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (PurgeInterfaceLeaseMessage $msg) {
                return $msg->ipv4 === '10.0.0.9' && $msg->ipv6 === null;
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->em->expects($this->once())->method('remove')->with($iface);
        $this->em->expects($this->once())->method('flush');

        $this->service->purgeMany([$iface]);
    }

    public function testPurgeManyFallsBackToLastDhcpIpv4WhenNoStaticReservation(): void
    {
        $iface = (new NetworkInterface())->setLastDhcpIp('192.168.60.32');

        $this->bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (PurgeInterfaceLeaseMessage $msg) {
                return $msg->ipv4 === '192.168.60.32' && $msg->ipv6 === null;
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->em->expects($this->once())->method('remove')->with($iface);
        $this->em->expects($this->once())->method('flush');

        $this->service->purgeMany([$iface]);
    }

    public function testPurgeManyFallsBackToLastDhcpIpv6WhenNoStaticReservation(): void
    {
        $iface = (new NetworkInterface())->setLastDhcpIp('fd00::abcd');

        $this->bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (PurgeInterfaceLeaseMessage $msg) {
                return $msg->ipv4 === null && $msg->ipv6 === 'fd00::abcd';
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->em->expects($this->once())->method('remove')->with($iface);
        $this->em->expects($this->once())->method('flush');

        $this->service->purgeMany([$iface]);
    }

    public function testPurgeManyPrefersStaticReservationOverLastDhcpIp(): void
    {
        $iface = (new NetworkInterface())
            ->setIpAddress((new IpAddress())->setAddress('10.0.0.5'))
            ->setLastDhcpIp('192.168.60.32');

        $this->bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (PurgeInterfaceLeaseMessage $msg) {
                return $msg->ipv4 === '10.0.0.5';
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->em->expects($this->once())->method('remove')->with($iface);
        $this->em->expects($this->once())->method('flush');

        $this->service->purgeMany([$iface]);
    }

    public function testPurgeManyWithNoInterfacesDoesNotFlush(): void
    {
        $this->bus->expects($this->never())->method('dispatch');
        $this->em->expects($this->never())->method('remove');
        $this->em->expects($this->never())->method('flush');

        $count = $this->service->purgeMany([]);

        $this->assertSame(0, $count);
    }
}
