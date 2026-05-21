<?php

namespace App\Controller\Api;

use App\Repository\AppSettingRepository;
use App\Repository\ArubaSwitchRepository;
use App\Repository\ClearpassAuthLogRepository;
use App\Repository\NetworkInterfaceRepository;
use App\Service\ArubaCxService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/switch')]
class SwitchApiController extends AbstractController
{
    public function __construct(
        private readonly ArubaSwitchRepository     $repo,
        private readonly ArubaCxService            $cx,
        private readonly ClearpassAuthLogRepository $authLogRepo,
        private readonly NetworkInterfaceRepository $ifaceRepo,
        private readonly AppSettingRepository       $settingRepo,
    ) {}

    #[Route('/port-status', name: 'api_switch_port_status', methods: ['GET'])]
    public function portStatus(Request $request): JsonResponse
    {
        $creds = $this->repo->getInstance();
        if ($creds === null) {
            return $this->json(['error' => 'No Aruba CX credentials configured'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $resolved = $this->resolveSwitch($request->query->all());
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        return $this->json($this->cx->getPortInfo($creds, $resolved['switch_ip'], $resolved['port_id']));
    }

    #[Route('/port-reauthenticate', name: 'api_switch_port_reauthenticate', methods: ['POST'])]
    public function portReauthenticate(Request $request): JsonResponse
    {
        return $this->runAction($request, 'reauth');
    }

    #[Route('/port-bounce', name: 'api_switch_port_bounce', methods: ['POST'])]
    public function portBounce(Request $request): JsonResponse
    {
        return $this->runAction($request, 'bounce');
    }

    #[Route('/port-poe-bounce', name: 'api_switch_port_poe_bounce', methods: ['POST'])]
    public function portPoeBounce(Request $request): JsonResponse
    {
        return $this->runAction($request, 'poe-bounce');
    }

    private function runAction(Request $request, string $action): JsonResponse
    {
        $creds = $this->repo->getInstance();
        if ($creds === null) {
            return $this->json(['error' => 'No Aruba CX credentials configured'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $data     = json_decode($request->getContent(), true) ?? [];
        $resolved = $this->resolveSwitch($data);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $result = match ($action) {
            'reauth'     => $this->cx->reauthenticatePort($creds, $resolved['switch_ip'], $resolved['port_id']),
            'bounce'     => $this->cx->bouncePort($creds, $resolved['switch_ip'], $resolved['port_id']),
            'poe-bounce' => $this->cx->poeBouncePort($creds, $resolved['switch_ip'], $resolved['port_id']),
        };

        if (!$result['success']) {
            return $this->json($result, Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json($result);
    }

    /**
     * Resolves switch IP and port ID from the given parameters.
     * Accepts: mac, ip, or explicit switch_ip + port_id.
     *
     * @return array{switch_ip: string, port_id: string}|JsonResponse
     */
    private function resolveSwitch(array $params): array|JsonResponse
    {
        $mac      = trim((string) ($params['mac']       ?? ''));
        $ip       = trim((string) ($params['ip']        ?? ''));
        $switchIp = trim((string) ($params['switch_ip'] ?? ''));
        $portId   = trim((string) ($params['port_id']   ?? ''));

        if ($mac === '' && $ip !== '') {
            $iface = $this->ifaceRepo->findByIpString($ip);
            if ($iface === null) {
                return $this->json(['error' => 'No interface found for IP ' . $ip], Response::HTTP_NOT_FOUND);
            }
            $mac = $iface->getMacAddress();
        }

        if ($mac !== '') {
            $mac    = $this->normaliseMac($mac);
            $maxAge = $this->settingRepo->getInstance()->getSwitchInfoMaxAgeDays();
            $cutoff = $maxAge !== null ? new \DateTimeImmutable("-{$maxAge} days") : null;
            $log    = $this->authLogRepo->findLatestWithSwitchInfoByMac($mac, $cutoff);

            if ($log === null || $log->getNasIp() === null || $log->getNasPortId() === null) {
                return $this->json(['error' => 'No switch info found for this address'], Response::HTTP_NOT_FOUND);
            }

            return ['switch_ip' => $log->getNasIp(), 'port_id' => $log->getNasPortId()];
        }

        if ($switchIp === '' || $portId === '') {
            return $this->json(
                ['error' => 'Provide mac, ip, or both switch_ip and port_id'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return ['switch_ip' => $switchIp, 'port_id' => $portId];
    }

    /** Normalise MAC to lowercase colon-separated format (handles colons, hyphens, bare hex). */
    private function normaliseMac(string $mac): string
    {
        $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $mac) ?? '');
        if (strlen($hex) === 12) {
            return implode(':', str_split($hex, 2));
        }
        return strtolower($mac);
    }
}
