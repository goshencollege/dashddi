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
        return $this->render('clearpass_auth_log/index.html.twig',
            $this->buildViewData($request, $logRepo, $ifaceRepo));
    }

    #[Route('/clearpass/auth-logs/table', name: 'clearpass_auth_log_table', methods: ['GET'])]
    public function table(
        Request $request,
        ClearpassAuthLogRepository $logRepo,
        NetworkInterfaceRepository $ifaceRepo,
    ): Response {
        return $this->render('clearpass_auth_log/_table.html.twig',
            $this->buildViewData($request, $logRepo, $ifaceRepo));
    }

    private function buildViewData(
        Request $request,
        ClearpassAuthLogRepository $logRepo,
        NetworkInterfaceRepository $ifaceRepo,
    ): array {
        $mac       = trim((string) $request->query->get('mac', ''));
        $username  = trim((string) $request->query->get('username', ''));
        $role      = trim((string) $request->query->get('role', ''));
        $vlan      = trim((string) $request->query->get('vlan', ''));
        $protocol  = trim((string) $request->query->get('protocol', ''));
        $service   = trim((string) $request->query->get('service', ''));
        $nasIp     = trim((string) $request->query->get('nas_ip', ''));
        $nasPortId = trim((string) $request->query->get('nas_port_id', ''));
        $page      = max(1, $request->query->getInt('page', 1));

        $result = $logRepo->search($mac, $username, $role, $vlan, $protocol, $service, $nasIp, $nasPortId, $page);
        $logs   = $result['items'];
        $macs   = array_unique(array_map(fn($l) => $l->getMacAddress(), $logs));

        return [
            'logs'               => $logs,
            'roles'              => $logRepo->findDistinctRoles(),
            'vlans'              => $logRepo->findDistinctVlans(),
            'protocols'          => $logRepo->findDistinctProtocols(),
            'services'           => $logRepo->findDistinctServices(),
            'filter_mac'         => $mac,
            'filter_username'    => $username,
            'filter_role'        => $role,
            'filter_vlan'        => $vlan,
            'filter_protocol'    => $protocol,
            'filter_service'     => $service,
            'filter_nas_ip'      => $nasIp,
            'filter_nas_port_id' => $nasPortId,
            'page'               => $page,
            'has_more'           => $result['hasMore'],
            'per_page'           => 50,
            'interface_map'      => $ifaceRepo->findByMacs($macs),
        ];
    }
}
