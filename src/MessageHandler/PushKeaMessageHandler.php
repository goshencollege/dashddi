<?php

namespace App\MessageHandler;

use App\Entity\PushLog;
use App\Message\PushKeaMessage;
use App\Repository\DhcpServerRepository;
use App\Service\KeaDeployService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PushKeaMessageHandler
{
    public function __construct(
        private readonly DhcpServerRepository $repo,
        private readonly KeaDeployService $deployer,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(PushKeaMessage $message): void
    {
        $server = $this->repo->find($message->serverId);
        if ($server === null) {
            return;
        }

        $startedAt = new \DateTimeImmutable();

        try {
            $result  = $this->deployer->deployToServer($server);
            $success = $this->isSuccess($result);
            $error   = null;
        } catch (\Throwable $e) {
            $result  = [];
            $success = false;
            $error   = $e->getMessage();
        }

        $log = new PushLog('dhcp', $server->getName(), $success, $result, $startedAt, new \DateTimeImmutable(), $error);
        $this->em->persist($log);
        $this->em->flush();
    }

    private function isSuccess(array $result): bool
    {
        foreach ($result as $svc) {
            if (!$svc['success']) {
                return false;
            }
            if (isset($svc['reload']) && $svc['reload'] !== null && !$svc['reload']['success']) {
                return false;
            }
        }
        return true;
    }
}
