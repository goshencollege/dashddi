<?php

namespace App\Controller;

use App\Entity\DhcpLease;
use App\Entity\Subnet;
use App\Repository\DhcpLeaseRepository;
use App\Repository\NetworkInterfaceRepository;
use App\Repository\SubnetRepository;
use Doctrine\ORM\EntityManagerInterface;
use IPLib\Address\IPv6;
use IPLib\Factory as IpFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DhcpLeaseController extends AbstractController
{
    #[Route('/dhcp/leases', name: 'dhcp_lease_index', methods: ['GET'])]
    public function index(
        Request $request,
        DhcpLeaseRepository $leaseRepo,
        SubnetRepository $subnetRepo,
        NetworkInterfaceRepository $ifaceRepo,
    ): Response {
        $mac      = trim((string) $request->query->get('mac', ''));
        $ip       = trim((string) $request->query->get('ip', ''));
        $subnetId = (int) $request->query->get('subnet', 0);
        $page     = max(1, $request->query->getInt('page', 1));

        $subnet  = $subnetId ? $subnetRepo->find($subnetId) : null;
        $leases  = $leaseRepo->search($mac, $ip, $subnet, $page);
        $subnets = $subnetRepo->findBy([], ['name' => 'ASC']);

        $macs = array_unique(array_map(fn($l) => $l->getMacAddress(), iterator_to_array($leases)));

        return $this->render('dhcp_lease/index.html.twig', [
            'leases'        => $leases,
            'subnets'       => $subnets,
            'filter_mac'    => $mac,
            'filter_ip'     => $ip,
            'filter_subnet' => $subnetId,
            'page'          => $page,
            'total'         => count($leases),
            'per_page'      => 50,
            'interface_map' => $ifaceRepo->findByMacs($macs),
        ]);
    }

    #[Route('/api/dhcp/lease', name: 'api_dhcp_lease', methods: ['POST'])]
    public function receiveLease(
        Request $request,
        EntityManagerInterface $em,
        SubnetRepository $subnetRepo,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $ipStr  = trim((string) ($data['ip-address'] ?? ''));
        $macStr = trim((string) ($data['hw-address'] ?? ''));

        if ($ipStr === '' || $macStr === '') {
            return $this->json(['error' => 'ip-address and hw-address are required'], Response::HTTP_BAD_REQUEST);
        }

        $lease = new DhcpLease($macStr, $ipStr);
        $lease->setHostname($data['hostname'] ?? null ?: null);

        if (!empty($data['expire'])) {
            $lease->setLeaseExpires(
                (new \DateTimeImmutable())->setTimestamp((int) $data['expire'])
            );
        }

        $lease->setSubnet($this->findSubnetForIp($ipStr, $subnetRepo));

        $em->persist($lease);
        $em->flush();

        return $this->json(['id' => $lease->getId()], Response::HTTP_CREATED);
    }

    private function findSubnetForIp(string $ipStr, SubnetRepository $subnetRepo): ?Subnet
    {
        $parsed = IpFactory::parseAddressString($ipStr);
        if ($parsed === null) {
            return null;
        }

        $bestSubnet = null;
        $bestPrefixLen = -1;

        foreach ($subnetRepo->findAll() as $subnet) {
            $cidr = ($parsed instanceof IPv6) ? $subnet->getIpv6Cidr() : $subnet->getIpv4Cidr();
            if ($cidr === null) {
                continue;
            }
            $range = IpFactory::parseRangeString($cidr);
            if ($range === null || !$range->contains($parsed)) {
                continue;
            }
            $prefixLen = (int) substr($cidr, strrpos($cidr, '/') + 1);
            if ($prefixLen > $bestPrefixLen) {
                $bestPrefixLen = $prefixLen;
                $bestSubnet = $subnet;
            }
        }

        return $bestSubnet;
    }
}
