<?php

namespace App\MessageHandler;

use App\Entity\PushLog;
use App\Message\PullSnipeItMessage;
use App\Repository\SnipeItServerRepository;
use App\Service\SnipeItSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PullSnipeItMessageHandler
{
    public function __construct(
        private readonly SnipeItServerRepository $repo,
        private readonly SnipeItSyncService      $syncService,
        private readonly EntityManagerInterface  $em,
        private readonly ManagerRegistry         $registry,
    ) {}

    public function __invoke(PullSnipeItMessage $message): void
    {
        $server = $this->repo->find($message->serverId);
        if ($server === null) {
            return;
        }

        $serverName = $server->getName();
        $startedAt  = new \DateTimeImmutable();

        try {
            $result  = $this->syncService->syncFromServer($server);
            $success = empty($result['errors']);
            $error   = $success ? null : implode('; ', $result['errors']);
        } catch (\Throwable $e) {
            $result  = [];
            $success = false;
            $error   = $e->getMessage();
        }

        // A DB exception during sync closes the EM — reset it so we can still write the log
        $em = $this->em->isOpen() ? $this->em : $this->registry->resetManager();
        $log = new PushLog('snipeit', $serverName, $success, $result, $startedAt, new \DateTimeImmutable(), $error);
        $em->clear();
        $em->persist($log);
        $em->flush();
    }
}
