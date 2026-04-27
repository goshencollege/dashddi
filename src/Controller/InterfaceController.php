<?php

namespace App\Controller;

use App\Entity\Host;
use App\Entity\InterfaceName;
use App\Entity\NetworkInterface;
use App\Form\InterfaceNameType;
use App\Form\NetworkInterfaceType;
use App\Repository\NetworkInterfaceRepository;
use App\Service\IpAddressManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class InterfaceController extends AbstractController
{
    public function __construct(
        private readonly IpAddressManager $ipManager,
    ) {}

    #[Route('/hosts/{id}/interfaces/new', name: 'interface_new', methods: ['GET', 'POST'])]
    public function new(Request $request, Host $host, EntityManagerInterface $em): Response
    {
        $interface = new NetworkInterface();
        $interface->setHost($host);

        $form = $this->createForm(NetworkInterfaceType::class, $interface);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $errors = $this->validateIpInputs($form, $interface->getSubnet(), null);
            if ($errors) {
                foreach ($errors as $error) {
                    $this->addFlash('danger', $error);
                }
            } else {
                $this->handleIpAssignment($form, $interface);
                $em->persist($interface);
                $em->flush();
                $this->addFlash('success', 'Interface added.');
                return $this->redirectToRoute('host_show', ['id' => $host->getId()]);
            }
        }

        return $this->render('interface/form.html.twig', [
            'form'      => $form,
            'interface' => $interface,
            'host'      => $host,
            'title'     => 'Add Interface to ' . $host->getName(),
        ]);
    }

    #[Route('/interfaces/{id}', name: 'interface_show', methods: ['GET'])]
    public function show(NetworkInterface $interface): Response
    {
        return $this->render('interface/show.html.twig', ['interface' => $interface]);
    }

    #[Route('/interfaces/{id}/edit', name: 'interface_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, NetworkInterface $interface, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(NetworkInterfaceType::class, $interface, ['is_edit' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $errors = $this->validateIpInputs($form, $interface->getSubnet(), $interface);
            if ($errors) {
                foreach ($errors as $error) {
                    $this->addFlash('danger', $error);
                }
            } else {
                $this->handleIpAssignment($form, $interface, isEdit: true);
                $em->flush();
                $this->addFlash('success', 'Interface updated.');
                return $this->redirectToRoute('interface_show', ['id' => $interface->getId()]);
            }
        }

        return $this->render('interface/form.html.twig', [
            'form'      => $form,
            'interface' => $interface,
            'host'      => $interface->getHost(),
            'title'     => 'Edit Interface',
        ]);
    }

    #[Route('/interfaces/{id}/delete', name: 'interface_delete', methods: ['POST'])]
    public function delete(Request $request, NetworkInterface $interface, EntityManagerInterface $em): Response
    {
        $hostId = $interface->getHost()?->getId();
        if ($this->isCsrfTokenValid('delete_interface_' . $interface->getId(), $request->request->get('_token'))) {
            $em->remove($interface);
            $em->flush();
            $this->addFlash('success', 'Interface deleted.');
        }
        return $this->redirectToRoute('host_show', ['id' => $hostId]);
    }

    #[Route('/interfaces/{id}/names/new', name: 'interface_name_new', methods: ['GET', 'POST'])]
    public function nameNew(Request $request, NetworkInterface $interface, EntityManagerInterface $em): Response
    {
        $name = new InterfaceName();
        $form = $this->createForm(InterfaceNameType::class, $name);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $interface->addName($name);
            $em->persist($name);
            $em->flush();
            $this->addFlash('success', 'Name added.');
            return $this->redirectToRoute('interface_show', ['id' => $interface->getId()]);
        }

        return $this->render('interface/name_form.html.twig', [
            'form'      => $form,
            'interface' => $interface,
            'title'     => 'Add DNS Name',
        ]);
    }

    #[Route('/interfaces/{interfaceId}/names/{id}/edit', name: 'interface_name_edit', methods: ['GET', 'POST'])]
    public function nameEdit(Request $request, int $interfaceId, InterfaceName $name, NetworkInterfaceRepository $repo, EntityManagerInterface $em): Response
    {
        $interface = $repo->find($interfaceId);
        $form = $this->createForm(InterfaceNameType::class, $name);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Name updated.');
            return $this->redirectToRoute('interface_show', ['id' => $interfaceId]);
        }

        return $this->render('interface/name_form.html.twig', [
            'form'      => $form,
            'interface' => $interface,
            'title'     => 'Edit DNS Name',
        ]);
    }

    #[Route('/interfaces/{interfaceId}/names/{id}/delete', name: 'interface_name_delete', methods: ['POST'])]
    public function nameDelete(Request $request, int $interfaceId, InterfaceName $name, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_name_' . $name->getId(), $request->request->get('_token'))) {
            $em->remove($name);
            $em->flush();
            $this->addFlash('success', 'Name deleted.');
        }
        return $this->redirectToRoute('interface_show', ['id' => $interfaceId]);
    }

    /** JSON endpoint for the interface form to fetch available IPs when subnet changes. */
    #[Route('/api/subnets/{id}/available-ips', name: 'api_subnet_available_ips', methods: ['GET'])]
    public function availableIps(\App\Entity\Subnet $subnet): JsonResponse
    {
        return $this->json($this->ipManager->getAvailableIpv4($subnet, 100));
    }

    #[Route('/api/subnets/{id}/available-ipv6', name: 'api_subnet_available_ipv6', methods: ['GET'])]
    public function availableIpv6(\App\Entity\Subnet $subnet): JsonResponse
    {
        return $this->json($this->ipManager->getAvailableIpv6($subnet, 50));
    }

    private function validateIpInputs(\Symfony\Component\Form\FormInterface $form, ?\App\Entity\Subnet $subnet, ?NetworkInterface $current): array
    {
        $errors = [];

        if ($form->get('ipv4Assignment')->getData() === 'select' && $subnet) {
            $ip = trim((string) $form->get('ipv4AddressInput')->getData());
            if ($ip !== '') {
                $err = $this->ipManager->validateSpecifiedIpv4($ip, $subnet, $current);
                if ($err) {
                    $errors[] = $err;
                }
            }
        }

        if ($form->get('ipv6Assignment')->getData() === 'select' && $subnet) {
            $ip = trim((string) $form->get('ipv6AddressInput')->getData());
            if ($ip !== '') {
                $err = $this->ipManager->validateSpecifiedIpv6($ip, $subnet, $current);
                if ($err) {
                    $errors[] = $err;
                }
            }
        }

        return $errors;
    }

    private function handleIpAssignment(\Symfony\Component\Form\FormInterface $form, NetworkInterface $interface, bool $isEdit = false): void
    {
        $subnet = $interface->getSubnet();

        $ipv4Mode = $form->get('ipv4Assignment')->getData();
        if ($ipv4Mode !== 'keep') {
            if ($isEdit) $this->ipManager->releaseIpv4($interface);
            if ($ipv4Mode === 'auto' && $subnet?->getIpv4Cidr()) {
                $ip = $this->ipManager->findNextAvailableIpv4($subnet);
                if ($ip) $this->ipManager->assignIpv4($interface, $ip);
            } elseif ($ipv4Mode === 'select') {
                $ip = trim((string) $form->get('ipv4AddressInput')->getData());
                if ($ip !== '' && $subnet) $this->ipManager->assignIpv4($interface, $ip);
            }
        }

        $ipv6Mode = $form->get('ipv6Assignment')->getData();
        if ($ipv6Mode !== 'keep') {
            if ($isEdit) $this->ipManager->releaseIpv6($interface);
            if ($ipv6Mode === 'auto' && $subnet?->getIpv6Cidr()) {
                $ip = $this->ipManager->findNextAvailableIpv6($subnet, $interface->getMacAddress());
                if ($ip) $this->ipManager->assignIpv6($interface, $ip);
            } elseif ($ipv6Mode === 'select') {
                $ip = trim((string) $form->get('ipv6AddressInput')->getData());
                if ($ip !== '' && $subnet) $this->ipManager->assignIpv6($interface, $ip);
            }
        }
    }
}
