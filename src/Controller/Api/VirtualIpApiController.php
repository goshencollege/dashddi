<?php

namespace App\Controller\Api;

use App\Entity\IpAddress;
use App\Entity\Ipv6Address;
use App\Entity\NetworkInterface;
use App\Entity\VirtualIp;
use App\Enum\VirtualIpProtocol;
use App\Repository\NetworkInterfaceRepository;
use App\Repository\SubnetRepository;
use App\Repository\VirtualIpRepository;
use App\Service\IpAddressManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/virtual-ips')]
class VirtualIpApiController extends AbstractController
{
    #[Route('', name: 'api_virtual_ips_index', methods: ['GET'])]
    public function index(Request $request, VirtualIpRepository $repo): JsonResponse
    {
        $deletedParam = $request->query->get('deleted');
        $qb = $repo->createQueryBuilder('v');

        if ($deletedParam !== 'all') {
            $qb->where($request->query->getBoolean('deleted') ? 'v.deletedAt IS NOT NULL' : 'v.deletedAt IS NULL');
        }

        if ($subnetId = $request->query->getInt('subnet_id')) {
            $qb->andWhere('v.subnet = :sid')->setParameter('sid', $subnetId);
        }

        $vips = $qb->orderBy('v.label', 'ASC')->getQuery()->getResult();

        return $this->json(array_map($this->serialize(...), $vips));
    }

    #[Route('/{id}', name: 'api_virtual_ips_show', methods: ['GET'])]
    public function show(VirtualIp $virtualIp): JsonResponse
    {
        if ($virtualIp->isDeleted()) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->serialize($virtualIp));
    }

    #[Route('', name: 'api_virtual_ips_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        SubnetRepository $subnetRepo,
        IpAddressManager $ipManager,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        if (empty($data['label'])) {
            return $this->json(['error' => 'label is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (empty($data['subnet_id'])) {
            return $this->json(['error' => 'subnet_id is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $subnet = $subnetRepo->find($data['subnet_id']);
        if (!$subnet) {
            return $this->json(['error' => 'subnet_id not found'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $vip = new VirtualIp();
        $vip->setSubnet($subnet);
        $em->persist($vip);

        $this->applyFields($vip, $data);

        if ($error = $this->applyIpv4($vip, $data, $ipManager, $em)) {
            return $this->json(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($error = $this->applyIpv6($vip, $data, $ipManager, $em)) {
            return $this->json(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em->flush();

        return $this->json($this->serialize($vip), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_virtual_ips_update', methods: ['PATCH'])]
    public function update(
        Request $request,
        VirtualIp $virtualIp,
        EntityManagerInterface $em,
        SubnetRepository $subnetRepo,
        IpAddressManager $ipManager,
    ): JsonResponse {
        if ($virtualIp->isDeleted()) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('label', $data) && empty($data['label'])) {
            return $this->json(['error' => 'label cannot be empty'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (array_key_exists('subnet_id', $data)) {
            $subnet = $subnetRepo->find($data['subnet_id']);
            if (!$subnet) {
                return $this->json(['error' => 'subnet_id not found'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            if ($subnet !== $virtualIp->getSubnet()) {
                $this->releaseIpv4($virtualIp, $em);
                $this->releaseIpv6($virtualIp, $em);
            }
            $virtualIp->setSubnet($subnet);
        }

        $this->applyFields($virtualIp, $data, patch: true);

        if (array_key_exists('ip_address', $data)) {
            $this->releaseIpv4($virtualIp, $em);
            if ($error = $this->applyIpv4($virtualIp, $data, $ipManager, $em, isEdit: true)) {
                return $this->json(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        if (array_key_exists('ipv6_address', $data)) {
            $this->releaseIpv6($virtualIp, $em);
            if ($error = $this->applyIpv6($virtualIp, $data, $ipManager, $em, isEdit: true)) {
                return $this->json(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $em->flush();

        return $this->json($this->serialize($virtualIp));
    }

    #[Route('/{id}', name: 'api_virtual_ips_delete', methods: ['DELETE'])]
    public function delete(VirtualIp $virtualIp, EntityManagerInterface $em): JsonResponse
    {
        if (!$virtualIp->isDeleted()) {
            $virtualIp->softDelete();
            $em->flush();
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/restore', name: 'api_virtual_ips_restore', methods: ['POST'])]
    public function restore(VirtualIp $virtualIp, EntityManagerInterface $em): JsonResponse
    {
        if ($virtualIp->isDeleted()) {
            $virtualIp->restore();
            $em->flush();
        }

        return $this->json($this->serialize($virtualIp));
    }

    #[Route('/{id}/members', name: 'api_virtual_ips_add_member', methods: ['POST'])]
    public function addMember(
        Request $request,
        VirtualIp $virtualIp,
        EntityManagerInterface $em,
        NetworkInterfaceRepository $ifaceRepo,
    ): JsonResponse {
        if ($virtualIp->isDeleted()) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (empty($data['interface_id'])) {
            return $this->json(['error' => 'interface_id is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $interface = $ifaceRepo->find($data['interface_id']);
        if (!$interface || $interface->isDeleted()) {
            return $this->json(['error' => 'interface_id not found'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $virtualIp->addMemberInterface($interface);
        $em->flush();

        return $this->json($this->serialize($virtualIp));
    }

    #[Route('/{id}/members/{interfaceId}', name: 'api_virtual_ips_remove_member', methods: ['DELETE'])]
    public function removeMember(
        VirtualIp $virtualIp,
        int $interfaceId,
        EntityManagerInterface $em,
        NetworkInterfaceRepository $ifaceRepo,
    ): JsonResponse {
        if ($virtualIp->isDeleted()) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        $interface = $ifaceRepo->find($interfaceId);
        if ($interface) {
            $virtualIp->removeMemberInterface($interface);
            $em->flush();
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function applyFields(VirtualIp $vip, array $data, bool $patch = false): void
    {
        $has = fn(string $k) => !$patch || array_key_exists($k, $data);

        if ($has('label') && isset($data['label']))   { $vip->setLabel($data['label']); }
        if ($has('notes'))                             { $vip->setNotes($data['notes'] ?? null); }
        if ($has('vrid'))                              { $vip->setVrid(isset($data['vrid']) ? (int) $data['vrid'] : null); }

        if ($has('protocol') && isset($data['protocol'])) {
            $proto = VirtualIpProtocol::tryFrom($data['protocol']);
            if (!$proto) {
                return;
            }
            $vip->setProtocol($proto);
        }
    }

    private function applyIpv4(VirtualIp $vip, array $data, IpAddressManager $ipManager, EntityManagerInterface $em, bool $isEdit = false): ?string
    {
        $value = $data['ip_address'] ?? null;
        if (empty($value)) {
            return null;
        }

        $subnet = $vip->getSubnet();
        if (!$subnet) {
            return 'Cannot assign an IP address: the VIP has no subnet.';
        }
        if ($subnet->isContainer()) {
            return 'Cannot assign an IPv4 address to a VIP in a container subnet.';
        }

        if ($value === 'auto') {
            if (!$subnet->getIpv4Cidr()) {
                return 'Cannot auto-assign IPv4: the subnet has no IPv4 CIDR.';
            }
            $ip = $ipManager->findNextAvailableIpv4($subnet);
            if (!$ip) {
                return 'No available IPv4 addresses in subnet ' . $subnet->getIpv4Cidr() . '.';
            }
            $this->persistIpv4($vip, $ip, $subnet, $em);
            return null;
        }

        $currentIp = $isEdit ? $vip->getIpAddress() : null;
        $error = $ipManager->validateSpecifiedIpv4($value, $subnet, null, $currentIp);
        if ($error) {
            return $error;
        }
        $this->persistIpv4($vip, $value, $subnet, $em);
        return null;
    }

    private function applyIpv6(VirtualIp $vip, array $data, IpAddressManager $ipManager, EntityManagerInterface $em, bool $isEdit = false): ?string
    {
        $value = $data['ipv6_address'] ?? null;
        if (empty($value)) {
            return null;
        }

        $subnet = $vip->getSubnet();
        if (!$subnet) {
            return 'Cannot assign an IP address: the VIP has no subnet.';
        }
        if ($subnet->isContainer()) {
            return 'Cannot assign an IPv6 address to a VIP in a container subnet.';
        }

        if ($value === 'auto') {
            if (!$subnet->getIpv6Cidr()) {
                return 'Cannot auto-assign IPv6: the subnet has no IPv6 CIDR.';
            }
            $ip = $ipManager->findNextAvailableIpv6($subnet);
            if (!$ip) {
                return 'No available IPv6 addresses in subnet ' . $subnet->getIpv6Cidr() . '.';
            }
            $this->persistIpv6($vip, $ip, $subnet, $em);
            return null;
        }

        if ($value === 'auto_v4') {
            if (!$subnet->getIpv6Cidr()) {
                return 'Cannot auto-assign IPv6 from IPv4: the subnet has no IPv6 CIDR.';
            }
            $ipv4 = $vip->getIpAddress()?->getAddress();
            if (!$ipv4) {
                return 'Cannot auto-assign IPv6 from IPv4: the VIP has no IPv4 address. Assign one first or in the same request.';
            }
            $ip = $ipManager->findIpv6FromIpv4($subnet, $ipv4);
            if (!$ip) {
                return 'Could not derive an IPv6 address from ' . $ipv4 . ' within ' . $subnet->getIpv6Cidr() . '.';
            }
            $this->persistIpv6($vip, $ip, $subnet, $em);
            return null;
        }

        $currentIp = $isEdit ? $vip->getIpv6Address() : null;
        $error = $ipManager->validateSpecifiedIpv6($value, $subnet, null, $currentIp);
        if ($error) {
            return $error;
        }
        $this->persistIpv6($vip, $value, $subnet, $em);
        return null;
    }

    private function persistIpv4(VirtualIp $vip, string $address, \App\Entity\Subnet $subnet, EntityManagerInterface $em): void
    {
        $ip = new IpAddress();
        $ip->setAddress($address);
        $ip->setSubnet($subnet);
        $vip->setIpAddress($ip);
        $em->persist($ip);
    }

    private function persistIpv6(VirtualIp $vip, string $address, \App\Entity\Subnet $subnet, EntityManagerInterface $em): void
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

    private function serialize(VirtualIp $vip): array
    {
        return [
            'id'                   => $vip->getId(),
            'label'                => $vip->getLabel(),
            'protocol'             => $vip->getProtocol()->value,
            'vrid'                 => $vip->getVrid(),
            'notes'                => $vip->getNotes(),
            'subnet_id'            => $vip->getSubnet()?->getId(),
            'ip_address'           => $vip->getIpAddress()?->getAddress(),
            'ipv6_address'         => $vip->getIpv6Address()?->getAddress(),
            'member_interface_ids' => $vip->getMemberInterfaces()->map(fn($i) => $i->getId())->toArray(),
            'deleted_at'           => $vip->getDeletedAt()?->format(\DateTimeInterface::ATOM),
            'created_at'           => $vip->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at'           => $vip->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'created_by'           => $vip->getCreatedBy(),
            'updated_by'           => $vip->getUpdatedBy(),
        ];
    }
}
