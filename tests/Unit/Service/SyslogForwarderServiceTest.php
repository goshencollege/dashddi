<?php

namespace App\Tests\Unit\Service;

use App\Message\SyslogMessage;
use App\Service\SyslogForwarderService;
use PHPUnit\Framework\TestCase;

class SyslogForwarderServiceTest extends TestCase
{
    private SyslogForwarderService $service;

    protected function setUp(): void
    {
        $this->service = new SyslogForwarderService();
    }

    public function testSendUdpToUnreachableHostDoesNotThrow(): void
    {
        $msg = $this->makeMessage('create', 'Host', 42, 'test-host', 'user@example.com', '192.168.1.1', null);
        // Should complete silently even when the host is unreachable
        $this->service->send('udp', '192.0.2.1', 514, $msg);
        $this->assertTrue(true);
    }

    public function testSendTcpToUnreachableHostDoesNotThrow(): void
    {
        $msg = $this->makeMessage('update', 'Subnet', 7, '10.0.0.0/24', 'admin@example.com', null, ['name' => ['old', 'new']]);
        $this->service->send('tcp', '192.0.2.1', 514, $msg);
        $this->assertTrue(true);
    }

    public function testLoginMessageDoesNotThrow(): void
    {
        $msg = $this->makeMessage('login', null, null, 'user@example.com', 'user@example.com', '10.0.0.1', null);
        $this->service->send('udp', '192.0.2.1', 514, $msg);
        $this->assertTrue(true);
    }

    public function testEncryptedFieldRedactedDoesNotThrow(): void
    {
        $msg = $this->makeMessage('update', 'DnsServer', 1, 'dns1', 'admin@example.com', null, [
            'sshPrivateKey' => ['[redacted]', '[redacted]'],
        ]);
        $this->service->send('udp', '192.0.2.1', 514, $msg);
        $this->assertTrue(true);
    }

    private function makeMessage(
        string $action,
        ?string $entityType,
        ?int $entityId,
        string $entityLabel,
        ?string $userIdentifier,
        ?string $ipAddress,
        ?array $changedFields,
    ): SyslogMessage {
        return new SyslogMessage(
            action:         $action,
            entityType:     $entityType,
            entityId:       $entityId,
            entityLabel:    $entityLabel,
            userIdentifier: $userIdentifier,
            ipAddress:      $ipAddress,
            changedFields:  $changedFields,
            occurredAt:     new \DateTimeImmutable('2026-07-01T12:00:00Z'),
        );
    }
}
