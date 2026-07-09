<?php

namespace App\Tests\Unit\EventListener;

use App\Entity\NetworkInterface;
use App\EventListener\EntityPushListener;
use App\Service\PushScopeService;
use App\Service\PushSuppressionContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class EntityPushListenerTest extends TestCase
{
    private MessageBusInterface&MockObject $bus;
    private PushScopeService $scope;
    private PushSuppressionContext $suppression;
    private EntityPushListener $listener;

    protected function setUp(): void
    {
        $this->bus        = $this->createMock(MessageBusInterface::class);
        $this->scope      = $this->createStub(PushScopeService::class);
        $this->suppression = $this->createStub(PushSuppressionContext::class);
        $this->listener   = new EntityPushListener($this->scope, $this->bus, $this->suppression);
    }

    private function makeUpdateArgs(object $entity, array $changeset): PostUpdateEventArgs
    {
        $uow = $this->createStub(UnitOfWork::class);
        $uow->method('getEntityChangeSet')->willReturn($changeset);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        return new PostUpdateEventArgs($entity, $em);
    }

    private function makeFlushArgs(): PostFlushEventArgs
    {
        $em = $this->createStub(EntityManagerInterface::class);
        return new PostFlushEventArgs($em);
    }

    public function testOnlyLastAuthAtDoesNotTriggerPush(): void
    {
        $iface = new NetworkInterface();
        $args  = $this->makeUpdateArgs($iface, [
            'lastAuthAt' => [null, new \DateTimeImmutable()],
            'updatedAt'  => [null, new \DateTimeImmutable()],
            'updatedBy'  => [null, 'admin'],
        ]);

        $this->bus->expects($this->never())->method('dispatch');

        $this->listener->postUpdate($args);
        $this->listener->postFlush($this->makeFlushArgs());
    }

    public function testOnlyLastDhcpFieldsDoNotTriggerPush(): void
    {
        $iface = new NetworkInterface();
        $args  = $this->makeUpdateArgs($iface, [
            'lastDhcpAt' => [null, new \DateTimeImmutable()],
            'lastDhcpIp' => [null, '10.0.0.1'],
            'updatedAt'  => [null, new \DateTimeImmutable()],
            'updatedBy'  => [null, 'admin'],
        ]);

        $this->bus->expects($this->never())->method('dispatch');

        $this->listener->postUpdate($args);
        $this->listener->postFlush($this->makeFlushArgs());
    }

    public function testAllActivityTimestampsTogetherDoNotTriggerPush(): void
    {
        $iface = new NetworkInterface();
        $args  = $this->makeUpdateArgs($iface, [
            'lastAuthAt' => [null, new \DateTimeImmutable()],
            'lastDhcpAt' => [null, new \DateTimeImmutable()],
            'lastDhcpIp' => [null, '10.0.0.5'],
            'updatedAt'  => [null, new \DateTimeImmutable()],
            'updatedBy'  => [null, 'admin'],
        ]);

        $this->bus->expects($this->never())->method('dispatch');

        $this->listener->postUpdate($args);
        $this->listener->postFlush($this->makeFlushArgs());
    }

    public function testOnlySwitchIpDoesNotTriggerPush(): void
    {
        $iface = new NetworkInterface();
        $args  = $this->makeUpdateArgs($iface, [
            'switchIp'  => [null, '10.0.0.1'],
            'updatedAt' => [null, new \DateTimeImmutable()],
            'updatedBy' => [null, 'admin'],
        ]);

        $this->bus->expects($this->never())->method('dispatch');

        $this->listener->postUpdate($args);
        $this->listener->postFlush($this->makeFlushArgs());
    }

    public function testOnlySwitchPortDoesNotTriggerPush(): void
    {
        $iface = new NetworkInterface();
        $args  = $this->makeUpdateArgs($iface, [
            'switchPort' => [null, 'GigabitEthernet1/0/1'],
            'updatedAt'  => [null, new \DateTimeImmutable()],
            'updatedBy'  => [null, 'admin'],
        ]);

        $this->bus->expects($this->never())->method('dispatch');

        $this->listener->postUpdate($args);
        $this->listener->postFlush($this->makeFlushArgs());
    }

    public function testRealFieldChangeAlongsideActivityTimestampTriggersPush(): void
    {
        $iface = new NetworkInterface();
        $args  = $this->makeUpdateArgs($iface, [
            'hostname'   => ['old-host', 'new-host'],
            'lastAuthAt' => [null, new \DateTimeImmutable()],
            'updatedAt'  => [null, new \DateTimeImmutable()],
            'updatedBy'  => [null, 'admin'],
        ]);

        $this->scope->method('dnsServerIdsFor')->willReturn([1]);
        $this->scope->method('clearpassMacsFor')->willReturn([]);
        $this->scope->method('affectsDhcp')->willReturn(false);

        $this->bus->expects($this->atLeastOnce())
            ->method('dispatch')
            ->willReturn(new Envelope(new \stdClass()));

        $this->listener->postUpdate($args);
        $this->listener->postFlush($this->makeFlushArgs());
    }
}
