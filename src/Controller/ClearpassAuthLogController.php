<?php

namespace App\Controller;

use App\Entity\UserPreference;
use App\Repository\ClearpassAuthLogRepository;
use App\Repository\NetworkInterfaceRepository;
use App\Repository\UserPreferenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ClearpassAuthLogController extends AbstractController
{
    private const FILTER_KEYS = ['mac', 'username', 'role', 'vlan', 'protocol', 'service', 'nas_ip', 'nas_port_id'];

    #[Route('/clearpass/auth-logs', name: 'clearpass_auth_log_index', methods: ['GET'])]
    public function index(
        Request $request,
        ClearpassAuthLogRepository $logRepo,
        NetworkInterfaceRepository $ifaceRepo,
        UserPreferenceRepository $prefRepo,
        EntityManagerInterface $em,
    ): Response {
        $user  = $this->getUser();
        $pref  = $user ? $prefRepo->findByIdentifier($user->getUserIdentifier()) : null;
        $reset = $request->query->getBoolean('reset');

        if ($reset) {
            if ($user && $pref && $pref->getClearpassAuthLogSearch() !== null) {
                $pref->setClearpassAuthLogSearch(null);
                $em->flush();
            }
            return $this->redirectToRoute('clearpass_auth_log_index');
        }

        $hasExplicitState = $request->query->has('page') || (bool) array_filter(self::FILTER_KEYS, fn($k) => $request->query->has($k));

        if ($hasExplicitState) {
            if ($user) {
                $saved = [];
                foreach (self::FILTER_KEYS as $k) {
                    $v = trim((string) $request->query->get($k, ''));
                    if ($v !== '') {
                        $saved[$k] = $v;
                    }
                }
                if (!$pref) {
                    $pref = new UserPreference($user->getUserIdentifier());
                    $em->persist($pref);
                }
                $pref->setClearpassAuthLogSearch($saved !== [] ? $saved : null);
                $em->flush();
            }
        } elseif ($pref?->getClearpassAuthLogSearch()) {
            // Redirect so the restored filters land in the URL — the auto-refresh
            // AJAX call re-fetches using window.location.search, so it must be there.
            return $this->redirectToRoute('clearpass_auth_log_index', $pref->getClearpassAuthLogSearch());
        }

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
