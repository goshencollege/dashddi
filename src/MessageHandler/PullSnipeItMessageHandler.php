<?php

namespace App\MessageHandler;

use App\Entity\PushLog;
use App\Message\PullSnipeItMessage;
use App\Repository\SnipeItServerRepository;
use App\Service\SnipeItSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PullSnipeItMessageHandler
{
    public function __construct(
        private readonly SnipeItServerRepository $repo,
        private readonly SnipeItSyncService      $syncService,
        private readonly EntityManagerInterface  $em,
    ) {}

    public function __invoke(PullSnipeItMessage $message): void
    {
        $server = $this->repo->find($message->serverId);
        if ($server === null) {
            return;
        }

        $startedAt = new \DateTimeImmutable();

        try {
            $result  = $this->syncService->syncFromServer($server);
            $success = empty($result['errors']);
            $error   = $success ? null : implode('; ', $result['errors']);
        } catch (\Throwable $e) {
            $result  = [];
            $success = false;
            $error   = $e->getMessage();
        }

        $log = new PushLog('snipeit', $server->getName(), $success, $result, $startedAt, new \DateTimeImmutable(), $error);
        $this->em->clear();
        $this->em->persist($log);
        $this->em->flush();
    }
}
