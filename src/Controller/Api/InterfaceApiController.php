<?php

namespace App\Controller\Api;

use App\Entity\NetworkInterface;
use App\Repository\HostRepository;
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

#[Route('/api/interfaces')]
class InterfaceApiController extends AbstractController
{
    #[Route('', name: 'api_interfaces_index', methods: ['GET'])]
    public function index(Request $request, NetworkInterfaceRepository $repo): JsonResponse
    {
        $deletedParam = $request->query->get('deleted');
        $qb = $repo->createQueryBuilder('i');
        if ($deletedParam !== 'all') {
            $qb->where($request->query->getBoolean('deleted') ? 'i.deletedAt IS NOT NULL' : 'i.deletedAt IS NULL');
        }

        if ($hostId = $request->query->getInt('host_id')) {
            $qb->andWhere('i.host = :hid')->setParameter('hid', $hostId);
        }
        if ($subnetId = $request->query->getInt('subnet_id')) {
            $qb->andWhere('i.subnet = :sid')->setParameter('sid', $subnetId);
        }
        if ($mac = $request->query->get('mac_address')) {
            $qb->andWhere('i.macAddress = :mac')->setParameter('mac', $mac);
        }

        $interfaces = $qb->orderBy('i.id', 'ASC')->getQuery()->getResult();

        return $this->json(array_map($this->serialize(...), $interfaces));
    }

    #[Route('/search', name: 'api_interfaces_search', methods: ['GET'])]
    public function search(Request $request, NetworkInterfaceRepository $repo, SubnetRepository $subnetRepo): JsonResponse
    {
        $q = trim($request->query->get('q', ''));
        if (strlen($q) < 2) {
            return $this->json([]);
        }
        $limit    = min(50, max(1, (int) $request->query->get('limit', 20)));
        $subnetId = $request->query->get('subnet_id');
        $subnet   = $subnetId ? $subnetRepo->find((int) $subnetId) : null;
        $results  = $repo->search($q, $limit, $subnet);
        return $this->json(array_map(function (NetworkInterface $iface) {
            $host = $iface->getHost();
            return [
                'id'         => $iface->getId(),
                'label'      => ($host ? $host->getName() . ': ' : '') . ($iface->getName() ?: $iface->getMacAddress()),
                'host_name'  => $host?->getName(),
                'iface_name' => $iface->getName(),
                'mac'        => $iface->getMacAddress(),
                'ip'         => $iface->getIpAddress()?->getAddress(),
                'ipv6'       => $iface->getIpv6Address()?->getAddress(),
            ];
        }, $results));
    }

    #[Route('/{id}', name: 'api_interfaces_show', methods: ['GET'])]
    public function show(NetworkInterface $interface): JsonResponse
    {
        if ($interface->isDeleted()) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }
        return $this->json($this->serialize($interface));
    }

    #[Route('', name: 'api_interfaces_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        HostRepository $hostRepo,
        SubnetRepository $subnetRepo,
        IpAddressManager $ipManager,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        if (empty($data['host_id'])) {
            return $this->json(['error' => 'host_id is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (empty($data['mac_address'])) {
            return $this->json(['error' => 'mac_address is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $host = $hostRepo->find($data['host_id']);
        if (!$host) {
            return $this->json(['error' => 'host_id not found'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $iface = new NetworkInterface();
        $iface->setHost($host);
        $iface->setMacAddress($data['mac_address']);
        $iface->setName($data['name'] ?? null);

        if (!empty($data['subnet_id'])) {
            $subnet = $subnetRepo->find($data['subnet_id']);
            if (!$subnet) {
                return $this->json(['error' => 'subnet_id not found'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $iface->setSubnet($subnet);
        }

        $em->persist($iface);

        if ($error = $this->applyIpv4($iface, $data, $ipManager)) {
            return $this->json(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($error = $this->applyIpv6($iface, $data, $ipManager)) {
            return $this->json(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em->flush();

        return $this->json($this->serialize($iface), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_interfaces_update', methods: ['PATCH'])]
    public function update(
        Request $request,
        NetworkInterface $interface,
        EntityManagerInterface $em,
        HostRepository $hostRepo,
        SubnetRepository $subnetRepo,
        IpAddressManager $ipManager,
        VirtualIpRepository $vipRepo,
    ): JsonResponse {
        if ($interface->isDeleted()) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }
        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('mac_address', $data)) {
            if (empty($data['mac_address'])) {
                return $this->json(['error' => 'mac_address cannot be empty'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $interface->setMacAddress($data['mac_address']);
        }

        if (array_key_exists('name', $data)) {
            $interface->setName($data['name']);
        }

        if (array_key_exists('host_id', $data)) {
            $host = $hostRepo->find($data['host_id']);
            if (!$host) {
                return $this->json(['error' => 'host_id not found'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $interface->setHost($host);
        }

        $subnetChanged = false;
        if (array_key_exists('subnet_id', $data)) {
            $subnet = $data['subnet_id'] ? $subnetRepo->find($data['subnet_id']) : null;
            if ($data['subnet_id'] && !$subnet) {
                return $this->json(['error' => 'subnet_id not found'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $subnetChanged = $subnet !== $interface->getSubnet();
            if ($subnetChanged) {
                $ipManager->releaseIpv4($interface);
                $ipManager->releaseIpv6($interface);
                foreach ($vipRepo->findByMemberInterface($interface) as $vip) {
                    if (!$subnet
                        || (!$ipManager->isVipIpv4ValidInSubnet($vip, $subnet)
                            && !$ipManager->isVipIpv6ValidInSubnet($vip, $subnet))) {
                        $vip->removeMemberInterface($interface);
                    }
                }
            }
            $interface->setSubnet($subnet);
        }

        if (array_key_exists('ip_address', $data)) {
            if (!$subnetChanged) {
                $ipManager->releaseIpv4($interface);
            }
            if ($error = $this->applyIpv4($interface, $data, $ipManager, isEdit: true)) {
                return $this->json(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        if (array_key_exists('ipv6_address', $data)) {
            if (!$subnetChanged) {
                $ipManager->releaseIpv6($interface);
            }
            if ($error = $this->applyIpv6($interface, $data, $ipManager, isEdit: true)) {
                return $this->json(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $em->flush();

        return $this->json($this->serialize($interface));
    }

    #[Route('/{id}', name: 'api_interfaces_delete', methods: ['DELETE'])]
    public function delete(NetworkInterface $interface, EntityManagerInterface $em): JsonResponse
    {
        if ($interface->isDeleted()) {
            return $this->json(null, Response::HTTP_NO_CONTENT);
        }
        $interface->softDelete();
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/restore', name: 'api_interfaces_restore', methods: ['POST'])]
    public function restore(NetworkInterface $interface, EntityManagerInterface $em): JsonResponse
    {
        if (!$interface->isDeleted()) {
            return $this->json($this->serialize($interface));
        }
        $interface->restore();
        // If the parent host was also soft-deleted, restore it too
        if ($interface->getHost()?->isDeleted()) {
            $interface->getHost()->restore();
        }
        $em->flush();

        return $this->json($this->serialize($interface));
    }

    /**
     * Handles ip_address field. Values:
     *   "auto"       – assign next available IPv4 from the interface's subnet
     *   "<ip>"       – assign the specified address (validated)
     *   null / ""    – release / leave unassigned
     *
     * Returns an error string on failure, null on success.
     */
    private function applyIpv4(NetworkInterface $iface, array $data, IpAddressManager $ipManager, bool $isEdit = false): ?string
    {
        $value = $data['ip_address'] ?? null;
        if (empty($value)) {
            return null;
        }

        $subnet = $iface->getSubnet();

        if ($subnet?->isContainer()) {
            return 'Cannot assign an IPv4 address to an interface in a container subnet.';
        }

        if ($value === 'auto') {
            if (!$subnet?->getIpv4Cidr()) {
                return 'Cannot auto-assign IPv4: the interface has no subnet with an IPv4 CIDR.';
            }
            $ip = $ipManager->findNextAvailableIpv4($subnet);
            if (!$ip) {
                return 'No available IPv4 addresses in subnet ' . $subnet->getIpv4Cidr() . '.';
            }
            $ipManager->assignIpv4($iface, $ip);
            return null;
        }

        if ($subnet) {
            $error = $ipManager->validateSpecifiedIpv4($value, $subnet, $isEdit ? $iface : null);
            if ($error) {
                return $error;
            }
        }
        $ipManager->assignIpv4($iface, $value);
        return null;
    }

    /**
     * Handles ipv6_address field. Values:
     *   "auto"       – assign next available IPv6 from the interface's subnet (uses MAC for EUI-64)
     *   "auto_v4"    – derive IPv6 from the interface's current IPv4 address
     *   "<ip>"       – assign the specified address (validated)
     *   null / ""    – release / leave unassigned
     *
     * Returns an error string on failure, null on success.
     */
    private function applyIpv6(NetworkInterface $iface, array $data, IpAddressManager $ipManager, bool $isEdit = false): ?string
    {
        $value = $data['ipv6_address'] ?? null;
        if (empty($value)) {
            return null;
        }

        $subnet = $iface->getSubnet();

        if ($subnet?->isContainer()) {
            return 'Cannot assign an IPv6 address to an interface in a container subnet.';
        }

        if ($value === 'auto') {
            if (!$subnet?->getIpv6Cidr()) {
                return 'Cannot auto-assign IPv6: the interface has no subnet with an IPv6 CIDR.';
            }
            $ip = $ipManager->findNextAvailableIpv6($subnet, $iface->getMacAddress());
            if (!$ip) {
                return 'No available IPv6 addresses in subnet ' . $subnet->getIpv6Cidr() . '.';
            }
            $ipManager->assignIpv6($iface, $ip);
            return null;
        }

        if ($value === 'auto_v4') {
            if (!$subnet?->getIpv6Cidr()) {
                return 'Cannot auto-assign IPv6 from IPv4: the interface has no subnet with an IPv6 CIDR.';
            }
            $ipv4 = $iface->getIpAddress()?->getAddress();
            if (!$ipv4) {
                return 'Cannot auto-assign IPv6 from IPv4: the interface has no IPv4 address. Assign one first or in the same request.';
            }
            $ip = $ipManager->findIpv6FromIpv4($subnet, $ipv4);
            if (!$ip) {
                return 'Could not derive an IPv6 address from ' . $ipv4 . ' within ' . $subnet->getIpv6Cidr() . '.';
            }
            $ipManager->assignIpv6($iface, $ip);
            return null;
        }

        if ($subnet) {
            $error = $ipManager->validateSpecifiedIpv6($value, $subnet, $isEdit ? $iface : null);
            if ($error) {
                return $error;
            }
        }
        $ipManager->assignIpv6($iface, $value);
        return null;
    }

    private function serialize(NetworkInterface $iface): array
    {
        return [
            'id'           => $iface->getId(),
            'name'         => $iface->getName(),
            'mac_address'  => $iface->getMacAddress(),
            'host_id'      => $iface->getHost()?->getId(),
            'subnet_id'    => $iface->getSubnet()?->getId(),
            'ip_address'   => $iface->getIpAddress()?->getAddress(),
            'ipv6_address' => $iface->getIpv6Address()?->getAddress(),
            'deleted_at'   => $iface->getDeletedAt()?->format(\DateTimeInterface::ATOM),
            'created_at'   => $iface->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at'   => $iface->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'created_by'   => $iface->getCreatedBy(),
            'updated_by'   => $iface->getUpdatedBy(),
        ];
    }
}
