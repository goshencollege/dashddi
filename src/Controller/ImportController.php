<?php

namespace App\Controller;

use App\Entity\Host;
use App\Entity\IpAddress;
use App\Entity\Ipv6Address;
use App\Entity\NetworkInterface;
use App\Entity\Subnet;
use App\Repository\IpAddressRepository;
use App\Repository\Ipv6AddressRepository;
use App\Repository\NetworkInterfaceRepository;
use App\Repository\SubnetRepository;
use App\Service\DhcpConfigParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/subnets/import')]
class ImportController extends AbstractController
{
    #[Route('', name: 'subnet_import', methods: ['GET', 'POST'])]
    public function upload(
        Request $request,
        DhcpConfigParser $parser,
        SubnetRepository $subnetRepo,
        NetworkInterfaceRepository $interfaceRepo,
        IpAddressRepository $ipRepo,
        Ipv6AddressRepository $ipv6Repo,
    ): Response {
        if (!$request->isMethod('POST')) {
            return $this->render('import/upload.html.twig');
        }

        $file = $request->files->get('config_file');
        if (!$file || !$file->isValid()) {
            $this->addFlash('danger', 'Please select a valid file to upload.');
            return $this->redirectToRoute('subnet_import');
        }

        $content = file_get_contents($file->getPathname());
        if ($content === false || $content === '') {
            $this->addFlash('danger', 'The uploaded file appears to be empty.');
            return $this->redirectToRoute('subnet_import');
        }

        $format = $request->request->get('format', 'auto');
        if ($format === 'auto') {
            $format = $parser->detectFormat($content);
        }

        $importSubnets      = (bool) $request->request->get('import_subnets');
        $importReservations = (bool) $request->request->get('import_reservations');

        if (!$importSubnets && !$importReservations) {
            $this->addFlash('warning', 'Please select at least one item type to import.');
            return $this->redirectToRoute('subnet_import');
        }

        $parsed = $parser->parse($content, $format);

        if (!empty($parsed['errors'])) {
            foreach ($parsed['errors'] as $error) {
                $this->addFlash('danger', 'Parse error: ' . $error);
            }
            return $this->redirectToRoute('subnet_import');
        }

        if (empty($parsed['subnets']) && empty($parsed['reservations'])) {
            $this->addFlash('warning', 'No subnets or host reservations were found in the uploaded file.');
            return $this->redirectToRoute('subnet_import');
        }

        $preview = $this->buildPreview(
            $parsed, $importSubnets, $importReservations,
            $subnetRepo, $interfaceRepo, $ipRepo, $ipv6Repo
        );

        $request->getSession()->set('dhcp_import', $preview);

        return $this->redirectToRoute('subnet_import_preview');
    }

    #[Route('/preview', name: 'subnet_import_preview', methods: ['GET'])]
    public function preview(Request $request): Response
    {
        $preview = $request->getSession()->get('dhcp_import');
        if (!$preview) {
            $this->addFlash('warning', 'No import data found. Please upload a config file first.');
            return $this->redirectToRoute('subnet_import');
        }

        return $this->render('import/preview.html.twig', ['preview' => $preview]);
    }

    #[Route('/confirm', name: 'subnet_import_confirm', methods: ['POST'])]
    public function confirm(
        Request $request,
        EntityManagerInterface $em,
        SubnetRepository $subnetRepo,
    ): Response {
        if (!$this->isCsrfTokenValid('dhcp_import_confirm', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('subnet_import_preview');
        }

        $preview = $request->getSession()->get('dhcp_import');
        if (!$preview) {
            $this->addFlash('warning', 'Session expired. Please upload the file again.');
            return $this->redirectToRoute('subnet_import');
        }

        $subnetsCreated = 0;
        $hostsCreated   = 0;
        $skipped        = 0;

        // Phase 1: pre-populate the CIDR→Subnet map with every already-existing subnet
        // from the parsed list, regardless of whether import_subnets is enabled.
        // This ensures reservations can always resolve their subnet even when the user
        // only asked to import reservations, or when the subnet exists from a prior import.
        $subnetMap = [];
        foreach ($preview['subnets'] as $s) {
            if ($s['status'] !== 'existing') {
                continue;
            }
            $existing = $s['version'] === 4
                ? $subnetRepo->findOneBy(['ipv4Cidr' => $s['cidr']])
                : $subnetRepo->findOneBy(['ipv6Cidr' => $s['cidr']]);
            if ($existing) {
                $subnetMap[$s['cidr']] = $existing;
            }
        }

        // Phase 2: create new subnets (only if the user asked for it)
        if ($preview['import_subnets']) {
            foreach ($preview['subnets'] as $s) {
                if ($s['status'] !== 'new') {
                    $skipped++;
                    continue;
                }

                $subnet = new Subnet();
                $subnet->setName($s['name'] ?: $s['cidr']);
                if ($s['version'] === 4) {
                    $subnet->setIpv4Cidr($s['cidr']);
                    if ($s['gateway']) {
                        $subnet->setGateway($s['gateway']);
                    }
                } else {
                    $subnet->setIpv6Cidr($s['cidr']);
                }
                $em->persist($subnet);
                $subnetMap[$s['cidr']] = $subnet;
                $subnetsCreated++;
            }
            if ($subnetsCreated > 0) {
                // Flush so subnets have IDs before we attach IpAddress/Ipv6Address to them
                $em->flush();
            }
        }

        if ($preview['import_reservations']) {
            foreach ($preview['reservations'] as $r) {
                if ($r['status'] !== 'new') {
                    $skipped++;
                    continue;
                }

                // Resolve subnet
                $subnet = $subnetMap[$r['subnet_cidr']] ?? null;
                if (!$subnet && $r['subnet_cidr'] !== '') {
                    $isV6   = str_contains($r['subnet_cidr'], ':');
                    $subnet = $isV6
                        ? $subnetRepo->findOneBy(['ipv6Cidr' => $r['subnet_cidr']])
                        : $subnetRepo->findOneBy(['ipv4Cidr' => $r['subnet_cidr']]);
                }

                $host = new Host();
                $host->setName($r['hostname'] ?: $r['mac']);

                $iface = new NetworkInterface();
                $iface->setMacAddress($r['mac']);
                $iface->setSubnet($subnet);

                if ($r['ipv4'] && $subnet) {
                    $ip = new IpAddress();
                    $ip->setAddress($r['ipv4']);
                    $ip->setSubnet($subnet);
                    $iface->setIpAddress($ip);
                    $em->persist($ip);
                }

                if ($r['ipv6'] && $subnet) {
                    $ip6 = new Ipv6Address();
                    $ip6->setAddress($r['ipv6']);
                    $ip6->setSubnet($subnet);
                    $iface->setIpv6Address($ip6);
                    $em->persist($ip6);
                }

                $host->addInterface($iface);
                $em->persist($host);
                $em->persist($iface);
                $hostsCreated++;
            }
            if ($hostsCreated > 0) {
                $em->flush();
            }
        }

        $request->getSession()->remove('dhcp_import');

        if ($subnetsCreated || $hostsCreated) {
            $this->addFlash('success', sprintf(
                'Import complete: %d subnet(s) and %d host reservation(s) created. %d item(s) skipped.',
                $subnetsCreated, $hostsCreated, $skipped
            ));
        } else {
            $this->addFlash('info', 'Nothing new to import — all items already existed and were skipped.');
        }

        return $this->redirectToRoute('subnet_index');
    }

    // -------------------------------------------------------------------------
    // Preview builder
    // -------------------------------------------------------------------------

    private function buildPreview(
        array $parsed,
        bool $importSubnets,
        bool $importReservations,
        SubnetRepository $subnetRepo,
        NetworkInterfaceRepository $interfaceRepo,
        IpAddressRepository $ipRepo,
        Ipv6AddressRepository $ipv6Repo,
    ): array {
        $preview = [
            'import_subnets'      => $importSubnets,
            'import_reservations' => $importReservations,
            'subnets'             => [],
            'reservations'        => [],
        ];

        foreach ($parsed['subnets'] as $s) {
            $existing = $s['version'] === 4
                ? $subnetRepo->findOneBy(['ipv4Cidr' => $s['cidr']])
                : $subnetRepo->findOneBy(['ipv6Cidr' => $s['cidr']]);

            $preview['subnets'][] = [
                'cidr'          => $s['cidr'],
                'version'       => $s['version'],
                'gateway'       => $s['gateway'],
                'name'          => $s['name'] ?? null,
                'status'        => $existing ? 'existing' : 'new',
                'existing_name' => $existing?->getName(),
            ];
        }

        // Pre-fetch all MACs from parsed reservations for efficient lookup.
        // Exclude the all-zeros placeholder — it is never a real duplicate match.
        $macs = array_filter(
            array_map(fn($r) => $this->normalizeMac($r['mac']), $parsed['reservations']),
            fn($m) => $m !== '00:00:00:00:00:00'
        );
        $ifaceByMac = $interfaceRepo->findByMacs(array_values($macs));

        // Pre-fetch all IPs
        $allIpv4 = array_filter(array_column($parsed['reservations'], 'ipv4'));
        $allIpv6 = array_filter(array_column($parsed['reservations'], 'ipv6'));

        $usedIpv4 = [];
        if ($allIpv4) {
            $rows = $ipRepo->createQueryBuilder('ip')
                ->where('ip.address IN (:addrs)')
                ->setParameter('addrs', array_values($allIpv4))
                ->getQuery()->getResult();
            foreach ($rows as $row) {
                $usedIpv4[$row->getAddress()] = true;
            }
        }

        $usedIpv6 = [];
        if ($allIpv6) {
            $rows = $ipv6Repo->createQueryBuilder('ip')
                ->where('ip.address IN (:addrs)')
                ->setParameter('addrs', array_values($allIpv6))
                ->getQuery()->getResult();
            foreach ($rows as $row) {
                $usedIpv6[$row->getAddress()] = true;
            }
        }

        foreach ($parsed['reservations'] as $r) {
            $mac   = $this->normalizeMac($r['mac']);
            $iface = ($mac !== '00:00:00:00:00:00') ? ($ifaceByMac[$mac] ?? null) : null;

            if ($iface) {
                $preview['reservations'][] = [
                    'hostname'       => $r['hostname'],
                    'mac'            => $mac,
                    'ipv4'           => $r['ipv4'],
                    'ipv6'           => $r['ipv6'],
                    'subnet_cidr'    => $r['subnet_cidr'],
                    'status'         => 'existing',
                    'conflict_reason'=> null,
                    'existing_host'  => $iface->getHost()?->getName(),
                ];
                continue;
            }

            $conflicts = [];
            if ($r['ipv4'] && isset($usedIpv4[$r['ipv4']])) {
                $conflicts[] = 'IPv4 ' . $r['ipv4'] . ' already assigned';
            }
            if ($r['ipv6'] && isset($usedIpv6[$r['ipv6']])) {
                $conflicts[] = 'IPv6 ' . $r['ipv6'] . ' already assigned';
            }

            $preview['reservations'][] = [
                'hostname'        => $r['hostname'],
                'mac'             => $mac,
                'ipv4'            => $r['ipv4'],
                'ipv6'            => $r['ipv6'],
                'subnet_cidr'     => $r['subnet_cidr'],
                'status'          => $conflicts ? 'conflict' : 'new',
                'conflict_reason' => $conflicts ? implode('; ', $conflicts) : null,
                'existing_host'   => null,
            ];
        }

        return $preview;
    }

    private function normalizeMac(string $mac): string
    {
        $hex = preg_replace('/[^0-9a-fA-F]/', '', $mac);
        if (strlen($hex) !== 12) {
            return strtolower($mac);
        }
        return implode(':', str_split(strtolower($hex), 2));
    }
}
