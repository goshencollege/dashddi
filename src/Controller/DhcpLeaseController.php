<?php

namespace App\Controller;

use App\Entity\DhcpLease;
use App\Entity\Subnet;
use App\Repository\DhcpLeaseRepository;
use App\Repository\SubnetRepository;
use Doctrine\ORM\EntityManagerInterface;
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
    ): Response {
        $mac      = trim((string) $request->query->get('mac', ''));
        $ip       = trim((string) $request->query->get('ip', ''));
        $subnetId = $request->query->getInt('subnet');
        $page     = max(1, $request->query->getInt('page', 1));

        $subnet  = $subnetId ? $subnetRepo->find($subnetId) : null;
        $leases  = $leaseRepo->search($mac, $ip, $subnet, $page);
        $subnets = $subnetRepo->findBy([], ['name' => 'ASC']);

        return $this->render('dhcp_lease/index.html.twig', [
            'leases'      => $leases,
            'subnets'     => $subnets,
            'filter_mac'  => $mac,
            'filter_ip'   => $ip,
            'filter_subnet' => $subnetId,
            'page'        => $page,
            'total'       => count($leases),
            'per_page'    => 50,
        ]);
    }

    /**
     * Kea calls this endpoint on each lease event.
     *
     * Expected JSON body (subset of Kea lease4 structure):
     *   { "ip-address": "x.x.x.x", "hw-address": "aa:bb:cc:dd:ee:ff",
     *     "hostname": "optional", "expire": <unix timestamp or null> }
     *
     * Secured by a bearer token set in the LEASE_API_KEY env variable.
     */
    #[Route('/api/kea/lease', name: 'api_kea_lease', methods: ['POST'])]
    public function receiveLease(
        Request $request,
        EntityManagerInterface $em,
        SubnetRepository $subnetRepo,
    ): JsonResponse {
        if (!$this->isApiTokenValid($request)) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

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

    private function isApiTokenValid(Request $request): bool
    {
        $expected = $_ENV['LEASE_API_KEY'] ?? '';
        if ($expected === '') {
            return false;
        }
        $auth  = $request->headers->get('Authorization', '');
        $token = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
        return hash_equals($expected, $token);
    }

    private function findSubnetForIp(string $ipStr, SubnetRepository $subnetRepo): ?Subnet
    {
        $parsed = IpFactory::parseAddressString($ipStr);
        if ($parsed === null) {
            return null;
        }

        foreach ($subnetRepo->findAll() as $subnet) {
            $cidr = $parsed->isVersion6() ? $subnet->getIpv6Cidr() : $subnet->getIpv4Cidr();
            if ($cidr === null) {
                continue;
            }
            $range = IpFactory::parseRangeString($cidr);
            if ($range !== null && $range->contains($parsed)) {
                return $subnet;
            }
        }

        return null;
    }
}
