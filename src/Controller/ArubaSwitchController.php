<?php

namespace App\Controller;

use App\Entity\ArubaSwitch;
use App\Form\ArubaSwitchType;
use App\Repository\ArubaSwitchRepository;
use App\Service\ArubaCxService;
use App\Service\SshKeyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/aruba-switch')]
class ArubaSwitchController extends AbstractController
{
    public function __construct(private readonly SshKeyService $sshKeys) {}

    #[Route('', name: 'aruba_switch_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('servers_index');
    }

    #[Route('/configure', name: 'aruba_switch_configure', methods: ['GET', 'POST'])]
    public function configure(Request $request, ArubaSwitchRepository $repo, EntityManagerInterface $em): Response
    {
        $creds  = $repo->getInstance();
        $isNew  = $creds === null;
        $creds ??= new ArubaSwitch();

        $existingPassword = $creds->getPassword();

        $form = $this->createForm(ArubaSwitchType::class, $creds);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($creds->getPassword() === null) {
                $creds->setPassword($existingPassword);
            }
            if ($isNew) {
                $keys = $this->sshKeys->generateKeyPair();
                $creds->setSshPrivateKey($keys['private'])->setSshPublicKey($keys['public']);
                $em->persist($creds);
                $this->addFlash('ssh_pubkey', $creds->getSshPublicKey());
            }
            $em->flush();
            $this->addFlash('success', 'Aruba CX credentials saved.');
            return $this->redirectToRoute('aruba_switch_configure');
        }

        return $this->render('aruba_switch/form.html.twig', [
            'form'  => $form,
            'creds' => $creds,
            'title' => 'Aruba CX Switch Credentials',
        ]);
    }

    #[Route('/regenerate-key', name: 'aruba_switch_regenerate_key', methods: ['POST'])]
    public function regenerateKey(Request $request, ArubaSwitchRepository $repo, EntityManagerInterface $em): Response
    {
        $creds = $repo->getInstance();
        if ($creds && $this->isCsrfTokenValid('regen_key_aruba', $request->request->get('_token'))) {
            $keys = $this->sshKeys->generateKeyPair();
            $creds->setSshPrivateKey($keys['private'])->setSshPublicKey($keys['public']);
            $em->flush();
            $this->addFlash('warning', 'SSH key regenerated. Add the new public key to authorized_keys on all switches.');
        }

        return $this->redirectToRoute('aruba_switch_configure');
    }

    #[Route('/delete', name: 'aruba_switch_delete', methods: ['POST'])]
    public function delete(Request $request, ArubaSwitchRepository $repo, EntityManagerInterface $em): Response
    {
        $creds = $repo->getInstance();
        if ($creds && $this->isCsrfTokenValid('delete_aruba_creds', $request->request->get('_token'))) {
            $em->remove($creds);
            $em->flush();
            $this->addFlash('success', 'Aruba CX credentials removed.');
        }
        return $this->redirectToRoute('aruba_switch_index');
    }

    // ── Interface-page AJAX endpoints ─────────────────────────────────────────
    // portId passed as query/body param to avoid routing issues with "1/1/5" slashes.

    #[Route('/port-status', name: 'aruba_switch_port_status', methods: ['GET'])]
    public function portStatus(Request $request, ArubaSwitchRepository $repo, ArubaCxService $cx): JsonResponse
    {
        $creds  = $repo->getInstance();
        $portId = trim((string) $request->query->get('portId', ''));
        $ip     = trim((string) $request->query->get('switchIp', ''));

        if ($creds === null) {
            return $this->json(['clients' => [], 'raw' => '', 'via' => 'none', 'error' => 'No Aruba CX credentials configured']);
        }
        if ($portId === '' || $ip === '') {
            return $this->json(['clients' => [], 'raw' => '', 'via' => 'none', 'error' => 'Missing portId or switchIp']);
        }

        try {
            return $this->json($cx->getPortInfo($creds, $ip, $portId));
        } catch (\Throwable $e) {
            return $this->json(['clients' => [], 'raw' => '', 'via' => 'none', 'error' => $e->getMessage()]);
        }
    }

    #[Route('/port-bounce', name: 'aruba_switch_port_bounce', methods: ['POST'])]
    public function portBounce(Request $request, ArubaSwitchRepository $repo, ArubaCxService $cx): JsonResponse
    {
        if (!$this->isCsrfTokenValid('bounce_port', $request->request->get('_token'))) {
            return $this->json(['success' => false, 'error' => 'Invalid CSRF token'], 403);
        }

        $creds  = $repo->getInstance();
        $portId = trim((string) $request->request->get('portId', ''));
        $ip     = trim((string) $request->request->get('switchIp', ''));

        if ($creds === null) {
            return $this->json(['success' => false, 'error' => 'No Aruba CX credentials configured']);
        }
        if ($portId === '' || $ip === '') {
            return $this->json(['success' => false, 'error' => 'Missing portId or switchIp']);
        }

        try {
            return $this->json($cx->bouncePort($creds, $ip, $portId));
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
