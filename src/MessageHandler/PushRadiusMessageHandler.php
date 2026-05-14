<?php

namespace App\MessageHandler;

use App\Entity\PushLog;
use App\Message\PushRadiusMessage;
use App\Repository\RadiusServerRepository;
use App\Service\RadiusDeployService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PushRadiusMessageHandler
{
    public function __construct(
        private readonly RadiusServerRepository $repo,
        private readonly RadiusDeployService $deployer,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(PushRadiusMessage $message): void
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

        $log = new PushLog('radius', $server->getName(), $success, $result, $startedAt, new \DateTimeImmutable(), $error);
        $this->em->clear();
        $this->em->persist($log);
        $this->em->flush();
    }

    private function isSuccess(array $result): bool
    {
        foreach (['clients.conf', 'authorize', 'sites-available/default', 'mods-enabled', 'sites-enabled'] as $key) {
            if (!($result[$key]['success'] ?? false)) {
                return false;
            }
        }
        if (isset($result['reload']) && !$result['reload']['success']) {
            return false;
        }
        return true;
    }
}
