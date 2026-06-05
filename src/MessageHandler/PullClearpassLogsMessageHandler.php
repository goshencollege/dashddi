<?php

namespace App\MessageHandler;

use App\Message\PullClearpassLogsMessage;
use App\Repository\ClearpassServerRepository;
use App\Service\ClearpassAuthLogService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PullClearpassLogsMessageHandler
{
    public function __construct(
        private readonly ClearpassServerRepository $repo,
        private readonly ClearpassAuthLogService   $logService,
    ) {}

    public function __invoke(PullClearpassLogsMessage $message): void
    {
        $servers = $this->repo->findBy([], ['name' => 'ASC']);

        foreach ($servers as $server) {
            $this->logService->pullFromServer($server);
        }
    }
}
