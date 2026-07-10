<?php

namespace App\MessageHandler;

use App\Entity\PushLog;
use App\Message\PushAllDhcpMessage;
use App\Repository\DhcpServerRepository;
use App\Service\DhcpDeployService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PushAllDhcpMessageHandler
{
    private const PAUSE_SECONDS = 15;

    public function __construct(
        private readonly DhcpServerRepository $repo,
        private readonly DhcpDeployService $deployer,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(PushAllDhcpMessage $message): void
    {
        $servers = $this->repo->findBy([], ['name' => 'ASC']);

        $first = true;
        foreach ($servers as $server) {
            if (!$first) {
                sleep(self::PAUSE_SECONDS);
            }
            $first = false;

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
            $this->em->clear();
            $this->em->persist($log);
            $this->em->flush();

            if (!$success) {
                return;
            }
        }
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
