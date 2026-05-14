<?php

namespace App\MessageHandler;

use App\Entity\PushLog;
use App\Message\PushDnsMessage;
use App\Repository\DnsServerRepository;
use App\Service\DnsDeployService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PushDnsMessageHandler
{
    public function __construct(
        private readonly DnsServerRepository $repo,
        private readonly DnsDeployService $deployer,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(PushDnsMessage $message): void
    {
        $server = $this->repo->find($message->serverId);
        if ($server === null) {
            return;
        }

        $startedAt = new \DateTimeImmutable();

        try {
            $result    = $this->deployer->deployToServer($server);
            $success   = $this->isSuccess($result);
            $error     = null;
        } catch (\Throwable $e) {
            $result  = [];
            $success = false;
            $error   = $e->getMessage();
        }

        $log = new PushLog('dns', $server->getName(), $success, $result, $startedAt, new \DateTimeImmutable(), $error);
        $this->em->clear();
        $this->em->persist($log);
        $this->em->flush();
    }

    private function isSuccess(array $result): bool
    {
        foreach ($result['views'] ?? [] as $viewResult) {
            if (isset($viewResult['mkdir']) && !$viewResult['mkdir']['success']) {
                return false;
            }
            foreach ($viewResult['zones'] ?? [] as $zone) {
                if (!$zone['success']) {
                    return false;
                }
            }
        }
        if (isset($result['conf']) && !$result['conf']['success']) {
            return false;
        }
        if (isset($result['reload']) && !$result['reload']['success']) {
            return false;
        }
        return true;
    }
}
