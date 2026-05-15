<?php

namespace App\Controller;

use App\Repository\ClearpassAuthLogRepository;
use App\Repository\ClearpassServerRepository;
use App\Repository\NetworkInterfaceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ClearpassAuthLogController extends AbstractController
{
    #[Route('/clearpass/auth-logs', name: 'clearpass_auth_log_index', methods: ['GET'])]
    public function index(
        Request $request,
        ClearpassAuthLogRepository $logRepo,
        ClearpassServerRepository $serverRepo,
        NetworkInterfaceRepository $ifaceRepo,
    ): Response {
        $mac      = trim((string) $request->query->get('mac', ''));
        $username = trim((string) $request->query->get('username', ''));
        $serverId = (int) $request->query->get('server', 0);
        $status   = trim((string) $request->query->get('status', ''));
        $page     = max(1, $request->query->getInt('page', 1));

        $server  = $serverId ? $serverRepo->find($serverId) : null;
        $logs    = $logRepo->search($mac, $username, $server, $status, $page);
        $servers = $serverRepo->findBy([], ['name' => 'ASC']);
        $statuses = $logRepo->findDistinctStatuses();

        $macs = array_unique(array_map(fn($l) => $l->getMacAddress(), iterator_to_array($logs)));

        return $this->render('clearpass_auth_log/index.html.twig', [
            'logs'            => $logs,
            'servers'         => $servers,
            'statuses'        => $statuses,
            'filter_mac'      => $mac,
            'filter_username' => $username,
            'filter_server'   => $serverId,
            'filter_status'   => $status,
            'page'            => $page,
            'total'           => count($logs),
            'per_page'        => 50,
            'interface_map'   => $ifaceRepo->findByMacs($macs),
        ]);
    }
}
