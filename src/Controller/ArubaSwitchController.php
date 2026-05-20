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

#[Route('/aruba-switches')]
class ArubaSwitchController extends AbstractController
{
    public function __construct(private readonly SshKeyService $sshKeys) {}

    #[Route('', name: 'aruba_switch_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('servers_index');
    }

    #[Route('/new', name: 'aruba_switch_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $switch = new ArubaSwitch();
        $form   = $this->createForm(ArubaSwitchType::class, $switch);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $keys = $this->sshKeys->generateKeyPair();
            $switch->setSshPrivateKey($keys['private'])->setSshPublicKey($keys['public']);
            $em->persist($switch);
            $em->flush();
            $this->addFlash('success', 'Switch "' . $switch->getName() . '" added.');
            $this->addFlash('ssh_pubkey', $switch->getSshPublicKey());
            return $this->redirectToRoute('servers_index');
        }

        return $this->render('aruba_switch/form.html.twig', [
            'form'   => $form,
            'switch' => $switch,
            'title'  => 'Add Aruba CX Switch',
        ]);
    }

    #[Route('/{id}/edit', name: 'aruba_switch_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, ArubaSwitch $switch, EntityManagerInterface $em): Response
    {
        $existingPassword = $switch->getPassword();
        $form = $this->createForm(ArubaSwitchType::class, $switch);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($switch->getPassword() === null) {
                $switch->setPassword($existingPassword);
            }
            $em->flush();
            $this->addFlash('success', 'Switch updated.');
            return $this->redirectToRoute('aruba_switch_edit', ['id' => $switch->getId()]);
        }

        return $this->render('aruba_switch/form.html.twig', [
            'form'   => $form,
            'switch' => $switch,
            'title'  => 'Edit: ' . $switch->getName(),
        ]);
    }

    #[Route('/{id}/regenerate-key', name: 'aruba_switch_regenerate_key', methods: ['POST'])]
    public function regenerateKey(Request $request, ArubaSwitch $switch, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('regen_key_aruba_' . $switch->getId(), $request->request->get('_token'))) {
            $keys = $this->sshKeys->generateKeyPair();
            $switch->setSshPrivateKey($keys['private'])->setSshPublicKey($keys['public']);
            $em->flush();
            $this->addFlash('warning', 'SSH key regenerated. Add the new public key to authorized_keys on "' . $switch->getName() . '".');
        }

        return $this->redirectToRoute('aruba_switch_edit', ['id' => $switch->getId()]);
    }

    #[Route('/{id}/delete', name: 'aruba_switch_delete', methods: ['POST'])]
    public function delete(Request $request, ArubaSwitch $switch, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_aruba_switch_' . $switch->getId(), $request->request->get('_token'))) {
            $em->remove($switch);
            $em->flush();
            $this->addFlash('success', 'Switch deleted.');
        }
        return $this->redirectToRoute('aruba_switch_index');
    }

    // ── Interface-page AJAX endpoints ─────────────────────────────────────────

    #[Route('/{id}/port/{portId}/status', name: 'aruba_switch_port_status', methods: ['GET'])]
    public function portStatus(ArubaSwitch $switch, string $portId, ArubaCxService $cx): JsonResponse
    {
        try {
            $info = $cx->getPortInfo($switch, $portId);
            return $this->json($info);
        } catch (\Throwable $e) {
            return $this->json(['clients' => [], 'raw' => '', 'via' => 'none', 'error' => $e->getMessage()]);
        }
    }

    #[Route('/{id}/port/{portId}/bounce', name: 'aruba_switch_port_bounce', methods: ['POST'])]
    public function portBounce(Request $request, ArubaSwitch $switch, string $portId, ArubaCxService $cx): JsonResponse
    {
        if (!$this->isCsrfTokenValid('bounce_port_' . $switch->getId(), $request->request->get('_token'))) {
            return $this->json(['success' => false, 'error' => 'Invalid CSRF token'], 403);
        }

        try {
            $result = $cx->bouncePort($switch, $portId);
            return $this->json($result);
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
