<?php

namespace App\Controller\Api;

use App\Entity\IpAddress;
use App\Entity\Ipv6Address;
use App\Entity\NetworkInterface;
use App\Repository\HostRepository;
use App\Repository\NetworkInterfaceRepository;
use App\Repository\SubnetRepository;
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
        $qb = $repo->createQueryBuilder('i');

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

    #[Route('/{id}', name: 'api_interfaces_show', methods: ['GET'])]
    public function show(NetworkInterface $interface): JsonResponse
    {
        return $this->json($this->serialize($interface));
    }

    #[Route('', name: 'api_interfaces_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        HostRepository $hostRepo,
        SubnetRepository $subnetRepo,
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

        $this->applyIpAddresses($iface, $data, $em);

        $em->persist($iface);
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
    ): JsonResponse {
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

        if (array_key_exists('subnet_id', $data)) {
            $subnet = $data['subnet_id'] ? $subnetRepo->find($data['subnet_id']) : null;
            if ($data['subnet_id'] && !$subnet) {
                return $this->json(['error' => 'subnet_id not found'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $interface->setSubnet($subnet);
        }

        if (array_key_exists('ip_address', $data) || array_key_exists('ipv6_address', $data)) {
            $this->applyIpAddresses($interface, $data, $em);
        }

        $em->flush();

        return $this->json($this->serialize($interface));
    }

    #[Route('/{id}', name: 'api_interfaces_delete', methods: ['DELETE'])]
    public function delete(NetworkInterface $interface, EntityManagerInterface $em): JsonResponse
    {
        $em->remove($interface);
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function applyIpAddresses(NetworkInterface $iface, array $data, EntityManagerInterface $em): void
    {
        if (array_key_exists('ip_address', $data)) {
            $existing = $iface->getIpAddress();
            if (empty($data['ip_address'])) {
                $iface->setIpAddress(null);
            } else {
                $ip = $existing ?? new IpAddress();
                $ip->setAddress($data['ip_address']);
                $ip->setSubnet($iface->getSubnet());
                if (!$existing) {
                    $em->persist($ip);
                }
                $iface->setIpAddress($ip);
            }
        }

        if (array_key_exists('ipv6_address', $data)) {
            $existing = $iface->getIpv6Address();
            if (empty($data['ipv6_address'])) {
                $iface->setIpv6Address(null);
            } else {
                $ip = $existing ?? new Ipv6Address();
                $ip->setAddress($data['ipv6_address']);
                $ip->setSubnet($iface->getSubnet());
                if (!$existing) {
                    $em->persist($ip);
                }
                $iface->setIpv6Address($ip);
            }
        }
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
            'created_at'   => $iface->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at'   => $iface->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'created_by'   => $iface->getCreatedBy(),
            'updated_by'   => $iface->getUpdatedBy(),
        ];
    }
}
