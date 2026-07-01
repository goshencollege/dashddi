<?php

namespace App\MessageHandler;

use App\Message\SyslogMessage;
use App\Repository\AppSettingRepository;
use App\Service\SyslogForwarderService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SyslogMessageHandler
{
    public function __construct(
        private readonly AppSettingRepository  $settingRepo,
        private readonly SyslogForwarderService $forwarder,
        private readonly LoggerInterface        $logger,
    ) {}

    public function __invoke(SyslogMessage $message): void
    {
        $setting = $this->settingRepo->getInstance();

        if (!$setting->isSyslogEnabled()) {
            return;
        }

        $host     = $setting->getSyslogHost();
        $port     = $setting->getSyslogPort() ?? 514;
        $protocol = $setting->getSyslogProtocol() ?? 'udp';

        if (empty($host)) {
            return;
        }

        try {
            $this->forwarder->send($protocol, $host, $port, $message);
        } catch (\Throwable $e) {
            $this->logger->warning('Syslog forwarding failed: ' . $e->getMessage());
        }
    }
}
