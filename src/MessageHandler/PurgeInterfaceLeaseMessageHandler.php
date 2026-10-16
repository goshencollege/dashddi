<?php

namespace App\MessageHandler;

use App\Entity\PushLog;
use App\Message\PurgeInterfaceLeaseMessage;
use App\Repository\DhcpServerRepository;
use App\Service\DhcpDeployService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PurgeInterfaceLeaseMessageHandler
{
    public function __construct(
        private readonly DhcpServerRepository $serverRepo,
        private readonly DhcpDeployService $deployer,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(PurgeInterfaceLeaseMessage $message): void
    {
        if ($message->ipv4 === null && $message->ipv6 === null) {
            return;
        }

        // No subnet-to-server mapping exists in this app (every subnet is pushed to
        // every DHCP server), so the lease could be on any of them — try them all,
        // best-effort, independently of one another.
        foreach ($this->serverRepo->findAll() as $server) {
            $scope     = $server->getVersionScope();
            $results   = [];
            $error     = null;
            $startedAt = new \DateTimeImmutable();

            try {
                if ($message->ipv4 !== null && $scope !== 'v6') {
                    $results['dhcp4'] = $this->deployer->deleteLease($server, 'dhcp4', $message->ipv4);
                }
                if ($message->ipv6 !== null && $scope !== 'v4') {
                    $results['dhcp6'] = $this->deployer->deleteLease($server, 'dhcp6', $message->ipv6);
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }

            if (empty($results) && $error === null) {
                continue;
            }

            $success = $error === null && !array_filter($results, fn(array $r) => !$r['success']);

            $log = new PushLog('dhcp-lease', $server->getName(), $success, $results, $startedAt, new \DateTimeImmutable(), $error);
            $this->em->clear();
            $this->em->persist($log);
            $this->em->flush();
        }
    }
}
