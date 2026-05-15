<?php

namespace App\Controller;

use App\Repository\ClearpassAuthLogRepository;
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
        NetworkInterfaceRepository $ifaceRepo,
    ): Response {
        $mac      = trim((string) $request->query->get('mac', ''));
        $username = trim((string) $request->query->get('username', ''));
        $status   = trim((string) $request->query->get('status', ''));
        $role     = trim((string) $request->query->get('role', ''));
        $vlan     = trim((string) $request->query->get('vlan', ''));
        $protocol = trim((string) $request->query->get('protocol', ''));
        $service  = trim((string) $request->query->get('service', ''));
        $page     = max(1, $request->query->getInt('page', 1));

        $logs = $logRepo->search($mac, $username, $status, $role, $vlan, $protocol, $service, $page);
        $macs = array_unique(array_map(fn($l) => $l->getMacAddress(), iterator_to_array($logs)));

        return $this->render('clearpass_auth_log/index.html.twig', [
            'logs'             => $logs,
            'statuses'         => $logRepo->findDistinctStatuses(),
            'roles'            => $logRepo->findDistinctRoles(),
            'vlans'            => $logRepo->findDistinctVlans(),
            'protocols'        => $logRepo->findDistinctProtocols(),
            'services'         => $logRepo->findDistinctServices(),
            'filter_mac'       => $mac,
            'filter_username'  => $username,
            'filter_status'    => $status,
            'filter_role'      => $role,
            'filter_vlan'      => $vlan,
            'filter_protocol'  => $protocol,
            'filter_service'   => $service,
            'page'             => $page,
            'total'            => count($logs),
            'per_page'         => 50,
            'interface_map'    => $ifaceRepo->findByMacs($macs),
        ]);
    }
}
