<?php

namespace App\MessageHandler;

use App\Entity\PushLog;
use App\Message\PushClearpassMessage;
use App\Repository\ClearpassServerRepository;
use App\Service\ClearpassDeployService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PushClearpassMessageHandler
{
    public function __construct(
        private readonly ClearpassServerRepository $repo,
        private readonly ClearpassDeployService $deployer,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(PushClearpassMessage $message): void
    {
        $server = $this->repo->find($message->serverId);
        if ($server === null) {
            return;
        }

        $startedAt = new \DateTimeImmutable();

        try {
            if ($message->mac !== null) {
                $result  = $this->deployer->pushSingleInterface($server, $message->mac);
                $success = $result['success'];
            } else {
                $result  = $this->deployer->deployToServer($server);
                $success = $result['success'];
            }
            $error = null;
        } catch (\Throwable $e) {
            $result  = [];
            $success = false;
            $error   = $e->getMessage();
        }

        $log = new PushLog('clearpass', $server->getName(), $success, $result, $startedAt, new \DateTimeImmutable(), $error);
        $this->em->clear();
        $this->em->persist($log);
        $this->em->flush();
    }
}
