<?php

namespace App\Controller\Api;

use App\Repository\ArubaSwitchRepository;
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
        private readonly ArubaSwitchRepository $repo,
        private readonly ArubaCxService        $cx,
    ) {}

    #[Route('/port-status', name: 'api_switch_port_status', methods: ['GET'])]
    public function portStatus(Request $request): JsonResponse
    {
        $creds  = $this->repo->getInstance();
        $portId = trim((string) $request->query->get('port_id', ''));
        $ip     = trim((string) $request->query->get('switch_ip', ''));

        if ($creds === null) {
            return $this->json(['error' => 'No Aruba CX credentials configured'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
        if ($portId === '' || $ip === '') {
            return $this->json(['error' => 'switch_ip and port_id are required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->cx->getPortInfo($creds, $ip, $portId));
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

        $data   = json_decode($request->getContent(), true) ?? [];
        $portId = trim((string) ($data['port_id'] ?? ''));
        $ip     = trim((string) ($data['switch_ip'] ?? ''));

        if ($portId === '' || $ip === '') {
            return $this->json(['error' => 'switch_ip and port_id are required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = match ($action) {
            'reauth'     => $this->cx->reauthenticatePort($creds, $ip, $portId),
            'bounce'     => $this->cx->bouncePort($creds, $ip, $portId),
            'poe-bounce' => $this->cx->poeBouncePort($creds, $ip, $portId),
        };

        if (!$result['success']) {
            return $this->json($result, Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json($result);
    }
}
