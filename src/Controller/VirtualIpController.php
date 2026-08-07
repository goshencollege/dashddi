<?php

namespace App\Controller;

use App\Entity\IpAddress;
use App\Entity\Ipv6Address;
use App\Entity\NetworkInterface;
use App\Entity\Subnet;
use App\Entity\VirtualIp;
use App\Form\VirtualIpType;
use App\Repository\VirtualIpRepository;
use App\Service\IpAddressManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class VirtualIpController extends AbstractController
{
    public function __construct(
        private readonly IpAddressManager $ipManager,
    ) {}

    #[Route('/subnets/{subnetId}/virtual-ips/new', name: 'virtual_ip_new', methods: ['GET', 'POST'])]
    public function new(Request $request, int $subnetId, EntityManagerInterface $em): Response
    {
        $subnet = $em->find(Subnet::class, $subnetId);
        if (!$subnet) {
            throw $this->createNotFoundException();
        }

        $from        = $request->query->get('from');
        $interfaceId = (int) $request->query->get('interfaceId', 0);
        $interface   = ($from === 'interface' && $interfaceId)
            ? $em->find(NetworkInterface::class, $interfaceId)
            : null;

        $vip = new VirtualIp();
        $vip->setSubnet($subnet);
        if ($interface) {
            $vip->addMemberInterface($interface);
        }

        $form = $this->createForm(VirtualIpType::class, $vip, ['subnet' => $subnet]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $errors = $this->validateIpInputs($form, $subnet, null, null);
            if ($errors) {
                foreach ($errors as $error) {
                    $this->addFlash('danger', $error);
                }
            } else {
                $this->handleIpAssignment($form, $vip, $em);
                $em->persist($vip);
                $em->flush();
                $this->addFlash('success', 'Virtual IP added.');
                if ($interface) {
                    return $this->redirectToRoute('interface_show', ['id' => $interface->getId()]);
                }
                return $this->redirectToRoute('virtual_ip_show', ['id' => $vip->getId()]);
            }
        }

        return $this->render('virtual_ip/form.html.twig', [
            'form'      => $form,
            'vip'       => $vip,
            'subnet'    => $subnet,
            'title'     => 'Add Virtual IP',
            'from'      => $from,
            'interface' => $interface,
        ]);
    }

    #[Route('/virtual-ips/{id}', name: 'virtual_ip_show', methods: ['GET'])]
    public function show(Request $request, VirtualIp $vip, EntityManagerInterface $em): Response
    {
        $from        = $request->query->get('from');
        $interfaceId = (int) $request->query->get('interfaceId', 0);
        $interface   = ($from === 'interface' && $interfaceId)
            ? $em->find(NetworkInterface::class, $interfaceId)
            : null;

        return $this->render('virtual_ip/show.html.twig', [
            'vip'       => $vip,
            'from'      => $from,
            'interface' => $interface,
        ]);
    }

    #[Route('/virtual-ips/{id}/edit', name: 'virtual_ip_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, VirtualIp $vip, EntityManagerInterface $em): Response
    {
        $subnet = $vip->getSubnet();

        $from        = $request->query->get('from');
        $interfaceId = (int) $request->query->get('interfaceId', 0);
        $interface   = ($from === 'interface' && $interfaceId)
            ? $em->find(NetworkInterface::class, $interfaceId)
            : null;

        $form = $this->createForm(VirtualIpType::class, $vip, [
            'is_edit' => true,
            'subnet'  => $subnet,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $errors = $this->validateIpInputs($form, $subnet, $vip->getIpAddress(), $vip->getIpv6Address());
            if ($errors) {
                foreach ($errors as $error) {
                    $this->addFlash('danger', $error);
                }
            } else {
                $this->handleIpAssignment($form, $vip, $em, isEdit: true);
                $em->flush();
                $this->addFlash('success', 'Virtual IP updated.');
                if ($interface) {
                    return $this->redirectToRoute('interface_show', ['id' => $interface->getId()]);
                }
                return $this->redirectToRoute('virtual_ip_show', ['id' => $vip->getId()]);
            }
        }

        return $this->render('virtual_ip/form.html.twig', [
            'form'      => $form,
            'vip'       => $vip,
            'subnet'    => $subnet,
            'title'     => 'Edit Virtual IP',
            'from'      => $from,
            'interface' => $interface,
        ]);
    }

    #[Route('/virtual-ips/{id}/delete', name: 'virtual_ip_delete', methods: ['POST'])]
    public function delete(Request $request, VirtualIp $vip, EntityManagerInterface $em): Response
    {
        $subnetId = $vip->getSubnet()?->getId();
        if ($this->isCsrfTokenValid('delete_vip_' . $vip->getId(), $request->request->get('_token'))) {
            $vip->softDelete();
            $em->flush();
            $this->addFlash('success', 'Virtual IP deleted.');
        }

        $referer = $request->headers->get('referer', '');
        if (preg_match('#/hosts/(\d+)#', $referer, $m)) {
            return $this->redirectToRoute('host_show', ['id' => (int) $m[1]]);
        }
        if (str_contains($referer, '/hosts')) {
            return $this->redirectToRoute('host_index');
        }
        return $this->redirectToRoute('subnet_show', ['id' => $subnetId]);
    }

    #[Route('/interfaces/{interfaceId}/link-vip', name: 'virtual_ip_link_interface', methods: ['POST'])]
    public function linkInterface(Request $request, int $interfaceId, EntityManagerInterface $em): Response
    {
        $interface = $em->find(NetworkInterface::class, $interfaceId);
        if (!$interface) {
            throw $this->createNotFoundException();
        }

        $vipId = (int) $request->request->get('vip_id');
        if ($this->isCsrfTokenValid('link_vip_' . $interfaceId, $request->request->get('_token'))) {
            $vip = $em->find(VirtualIp::class, $vipId);
            if ($vip && !$vip->isDeleted()) {
                $vip->addMemberInterface($interface);
                $em->flush();
            }
        }

        return $this->redirectToRoute('interface_show', ['id' => $interfaceId]);
    }

    #[Route('/virtual-ips/{id}/remove-member/{interfaceId}', name: 'virtual_ip_remove_member', methods: ['POST'])]
    public function removeMember(Request $request, VirtualIp $vip, int $interfaceId, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('remove_member_' . $vip->getId() . '_' . $interfaceId, $request->request->get('_token'))) {
            $interface = $em->find(NetworkInterface::class, $interfaceId);
            if ($interface) {
                $vip->removeMemberInterface($interface);
                $em->flush();
            }
        }

        $referer = $request->headers->get('referer', '');
        if (preg_match('#/hosts/(\d+)#', $referer, $m)) {
            return $this->redirectToRoute('host_show', ['id' => (int) $m[1]]);
        }
        if (str_contains($referer, '/hosts')) {
            return $this->redirectToRoute('host_index');
        }
        return $this->redirectToRoute('interface_show', ['id' => $interfaceId]);
    }

    #[Route('/virtual-ips/{id}/restore', name: 'virtual_ip_restore', methods: ['POST'])]
    public function restore(Request $request, VirtualIp $vip, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('restore_vip_' . $vip->getId(), $request->request->get('_token'))) {
            $vip->restore();
            $em->flush();
            $this->addFlash('success', 'Virtual IP restored.');
        }
        return $this->redirectToRoute('virtual_ip_show', ['id' => $vip->getId()]);
    }

    private function validateIpInputs(FormInterface $form, ?Subnet $subnet, ?IpAddress $currentIpv4, ?Ipv6Address $currentIpv6): array
    {
        if ($subnet?->isContainer()) {
            return [];
        }

        $errors = [];

        if ($form->get('ipv4Assignment')->getData() === 'select' && $subnet) {
            $ip = trim((string) $form->get('ipv4AddressInput')->getData());
            if ($ip !== '') {
                $err = $this->ipManager->validateSpecifiedIpv4($ip, $subnet, null, $currentIpv4);
                if ($err) {
                    $errors[] = $err;
                }
            }
        }

        if ($form->get('ipv6Assignment')->getData() === 'select' && $subnet) {
            $ip = trim((string) $form->get('ipv6AddressInput')->getData());
            if ($ip !== '') {
                $err = $this->ipManager->validateSpecifiedIpv6($ip, $subnet, null, $currentIpv6);
                if ($err) {
                    $errors[] = $err;
                }
            }
        }

        return $errors;
    }

    private function handleIpAssignment(FormInterface $form, VirtualIp $vip, EntityManagerInterface $em, bool $isEdit = false): void
    {
        $subnet = $vip->getSubnet();

        if ($subnet?->isContainer()) {
            return;
        }

        $ipv4Mode = $form->get('ipv4Assignment')->getData();
        if ($ipv4Mode !== 'keep') {
            if ($isEdit) $this->releaseIpv4($vip, $em);
            if ($ipv4Mode === 'auto' && $subnet?->getIpv4Cidr()) {
                $ip = $this->ipManager->findNextAvailableIpv4($subnet);
                if ($ip) $this->assignIpv4($vip, $ip, $subnet, $em);
            } elseif ($ipv4Mode === 'select') {
                $ip = trim((string) $form->get('ipv4AddressInput')->getData());
                if ($ip !== '' && $subnet) $this->assignIpv4($vip, $ip, $subnet, $em);
            }
        }

        $ipv6Mode = $form->get('ipv6Assignment')->getData();
        if ($ipv6Mode !== 'keep') {
            if ($isEdit) $this->releaseIpv6($vip, $em);
            if ($ipv6Mode === 'auto_v4' && $subnet?->getIpv6Cidr()) {
                $ipv4 = $vip->getIpAddress()?->getAddress();
                if ($ipv4) {
                    $ip = $this->ipManager->findIpv6FromIpv4($subnet, $ipv4);
                    if ($ip) $this->assignIpv6($vip, $ip, $subnet, $em);
                }
            } elseif ($ipv6Mode === 'select') {
                $ip = trim((string) $form->get('ipv6AddressInput')->getData());
                if ($ip !== '' && $subnet) $this->assignIpv6($vip, $ip, $subnet, $em);
            }
        }
    }

    private function assignIpv4(VirtualIp $vip, string $address, Subnet $subnet, EntityManagerInterface $em): void
    {
        $ip = new IpAddress();
        $ip->setAddress($address);
        $ip->setSubnet($subnet);
        $vip->setIpAddress($ip);
        $em->persist($ip);
    }

    private function assignIpv6(VirtualIp $vip, string $address, Subnet $subnet, EntityManagerInterface $em): void
    {
        $ip = new Ipv6Address();
        $ip->setAddress($address);
        $ip->setSubnet($subnet);
        $vip->setIpv6Address($ip);
        $em->persist($ip);
    }

    private function releaseIpv4(VirtualIp $vip, EntityManagerInterface $em): void
    {
        $ip = $vip->getIpAddress();
        if ($ip) {
            $vip->setIpAddress(null);
            $em->remove($ip);
        }
    }

    private function releaseIpv6(VirtualIp $vip, EntityManagerInterface $em): void
    {
        $ip = $vip->getIpv6Address();
        if ($ip) {
            $vip->setIpv6Address(null);
            $em->remove($ip);
        }
    }
}
