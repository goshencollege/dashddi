<?php

namespace App\Controller;

use App\Entity\Building;
use App\Entity\Domain;
use App\Entity\Host;
use App\Entity\InterfaceName;
use App\Entity\NetworkInterface;
use App\Entity\Tag;
use App\Repository\IpAddressRepository;
use App\Repository\Ipv6AddressRepository;
use App\Repository\NetworkInterfaceRepository;
use App\Repository\SubnetRepository;
use App\Service\HostCsvParser;
use App\Service\IpAddressManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/hosts/import')]
class HostImportController extends AbstractController
{
    #[Route('', name: 'host_import', methods: ['GET', 'POST'])]
    public function upload(
        Request $request,
        HostCsvParser $parser,
        EntityManagerInterface $em,
        NetworkInterfaceRepository $interfaceRepo,
        IpAddressRepository $ipRepo,
        Ipv6AddressRepository $ipv6Repo,
        SubnetRepository $subnetRepo,
    ): Response {
        if (!$request->isMethod('POST')) {
            return $this->render('host_import/upload.html.twig');
        }

        $file = $request->files->get('csv_file');
        if (!$file || !$file->isValid()) {
            $this->addFlash('danger', 'Please select a valid CSV file to upload.');
            return $this->redirectToRoute('host_import');
        }

        $content = file_get_contents($file->getPathname());
        if ($content === false || $content === '') {
            $this->addFlash('danger', 'The uploaded file appears to be empty.');
            return $this->redirectToRoute('host_import');
        }

        $parsed = $parser->parse($content);

        if (!empty($parsed['errors'])) {
            foreach ($parsed['errors'] as $error) {
                $this->addFlash('danger', $error);
            }
            return $this->redirectToRoute('host_import');
        }

        if (empty($parsed['entries'])) {
            $this->addFlash('warning', 'No host entries were found in the uploaded file.');
            return $this->redirectToRoute('host_import');
        }

        $preview = $this->buildPreview($parsed['entries'], $em, $interfaceRepo, $ipRepo, $ipv6Repo, $subnetRepo);

        $request->getSession()->set('host_csv_import', $preview);

        return $this->redirectToRoute('host_import_preview');
    }

    #[Route('/preview', name: 'host_import_preview', methods: ['GET'])]
    public function preview(Request $request): Response
    {
        $preview = $request->getSession()->get('host_csv_import');
        if (!$preview) {
            $this->addFlash('warning', 'No import data found. Please upload a CSV file first.');
            return $this->redirectToRoute('host_import');
        }

        return $this->render('host_import/preview.html.twig', ['preview' => $preview]);
    }

    #[Route('/confirm', name: 'host_import_confirm', methods: ['POST'])]
    public function confirm(
        Request $request,
        EntityManagerInterface $em,
        SubnetRepository $subnetRepo,
        IpAddressManager $ipManager,
    ): Response {
        if (!$this->isCsrfTokenValid('host_import_confirm', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('host_import_preview');
        }

        $preview = $request->getSession()->get('host_csv_import');
        if (!$preview) {
            $this->addFlash('warning', 'Session expired. Please upload the file again.');
            return $this->redirectToRoute('host_import');
        }

        // Refuse if the preview still contains any unresolved issues
        foreach ($preview['hosts'] as $h) {
            if ($h['status'] !== 'new') {
                $this->addFlash('danger', 'Import blocked: the preview contains unresolved issues. Fix the CSV and re-upload.');
                return $this->redirectToRoute('host_import_preview');
            }
            foreach ($h['interfaces'] as $i) {
                if ($i['status'] !== 'new') {
                    $this->addFlash('danger', 'Import blocked: the preview contains unresolved issues. Fix the CSV and re-upload.');
                    return $this->redirectToRoute('host_import_preview');
                }
            }
        }

        // Pre-load domains for DNS name creation
        $domains = [];
        foreach ($em->getRepository(Domain::class)->findAll() as $d) {
            $domains[strtolower($d->getName())] = $d;
        }

        // Pre-load buildings and tags for efficient lookup
        $buildings = [];
        foreach ($em->getRepository(Building::class)->findAll() as $b) {
            $buildings[strtolower($b->getName())] = $b;
        }
        $tags = [];
        foreach ($em->getRepository(Tag::class)->findAll() as $t) {
            $tags[strtolower($t->getName())] = $t;
        }

        $hostsCreated = 0;

        foreach ($preview['hosts'] as $h) {
            $host = new Host();
            $host->setName($h['hostname']);
            $host->setRoom($h['room'] ?: null);
            $host->setNotes($h['notes'] ?: null);

            if ($h['building_name']) {
                $building = $buildings[strtolower($h['building_name'])] ?? null;
                if ($building) {
                    $host->setBuilding($building);
                }
            }

            foreach ($h['tags'] as $tagName) {
                $key = strtolower($tagName);
                if (!isset($tags[$key])) {
                    $newTag = new Tag();
                    $newTag->setName($tagName);
                    $em->persist($newTag);
                    $tags[$key] = $newTag;
                }
                $host->addTag($tags[$key]);
            }

            $em->persist($host);

            foreach ($h['interfaces'] as $i) {
                $subnet = null;
                if ($i['subnet_cidr']) {
                    $isV6   = str_contains($i['subnet_cidr'], ':');
                    $subnet = $isV6
                        ? $subnetRepo->findOneBy(['ipv6Cidr' => $i['subnet_cidr']])
                        : $subnetRepo->findOneBy(['ipv4Cidr' => $i['subnet_cidr']]);
                }

                $iface = new NetworkInterface();
                $iface->setMacAddress($i['mac']);
                $iface->setName($i['name'] ?: null);
                $iface->setNotes($i['notes'] ?: null);
                $iface->setSubnet($subnet);
                $host->addInterface($iface);
                $em->persist($iface);

                if ($i['ip_address'] && $subnet) {
                    $ipManager->assignIpv4($iface, $i['ip_address']);
                }
                if ($i['ipv6_address'] && $subnet) {
                    $ipManager->assignIpv6($iface, $i['ipv6_address']);
                }

                if (!empty($i['dns_label']) && !empty($i['dns_domain'])) {
                    $domain = $domains[strtolower($i['dns_domain'])] ?? null;
                    if ($domain) {
                        $ifaceName = new InterfaceName();
                        $ifaceName->setName($i['dns_label']);
                        $ifaceName->setDomain($domain);
                        $iface->addName($ifaceName);
                        $em->persist($ifaceName);
                    }
                }
            }

            $hostsCreated++;
        }

        if ($hostsCreated > 0) {
            $em->flush();
        }

        $request->getSession()->remove('host_csv_import');

        $this->addFlash('success', sprintf(
            'Import complete: %d host(s) created.',
            $hostsCreated
        ));

        return $this->redirectToRoute('host_index');
    }

    #[Route('/template', name: 'host_import_template', methods: ['GET'])]
    public function template(HostCsvParser $parser): Response
    {
        return new Response(
            $parser->getTemplateCsvContent(),
            200,
            [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="host_import_template.csv"',
            ]
        );
    }

    // -------------------------------------------------------------------------
    // FQDN resolver
    // -------------------------------------------------------------------------

    /**
     * Given a FQDN and a list of Domain entities sorted longest-name-first,
     * returns [label, domainName] where label is a valid single DNS label,
     * or [null, null] if no domain produces a valid match.
     *
     * @param  Domain[] $domainsSortedByLength
     * @return array{string|null, string|null}
     */
    private function resolveFqdn(string $fqdn, array $domainsSortedByLength): array
    {
        $fqdnLower = strtolower(rtrim($fqdn, '.'));
        foreach ($domainsSortedByLength as $domain) {
            $suffix = strtolower($domain->getName());
            if (!str_ends_with($fqdnLower, '.' . $suffix)) {
                continue;
            }
            $label = substr($fqdn, 0, strlen($fqdn) - strlen($suffix) - 1);
            if (preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?$/', $label)) {
                return [$label, $domain->getName()];
            }
        }
        return [null, null];
    }

    // -------------------------------------------------------------------------
    // Preview builder
    // -------------------------------------------------------------------------

    private function buildPreview(
        array $entries,
        EntityManagerInterface $em,
        NetworkInterfaceRepository $interfaceRepo,
        IpAddressRepository $ipRepo,
        Ipv6AddressRepository $ipv6Repo,
        SubnetRepository $subnetRepo,
    ): array {
        // Pre-load all existing host names
        $hostnames    = array_column($entries, 'hostname');
        $existingRows = $em->createQueryBuilder()
            ->select('h.name')
            ->from(Host::class, 'h')
            ->where('h.name IN (:names)')
            ->andWhere('h.deletedAt IS NULL')
            ->setParameter('names', $hostnames)
            ->getQuery()
            ->getScalarResult();
        $existingHostNames = array_flip(array_column($existingRows, 'name'));

        // Pre-load all buildings and tags for resolution
        $buildings = [];
        foreach ($em->getRepository(Building::class)->findAll() as $b) {
            $buildings[strtolower($b->getName())] = $b->getName();
        }
        $tags = [];
        foreach ($em->getRepository(Tag::class)->findAll() as $t) {
            $tags[strtolower($t->getName())] = $t->getName();
        }

        // Resolve subnet names for all CIDRs referenced in the CSV
        $allCidrs = [];
        foreach ($entries as $entry) {
            foreach ($entry['interfaces'] as $iface) {
                if ($iface['subnet_cidr']) {
                    $allCidrs[$iface['subnet_cidr']] = true;
                }
            }
        }
        $subnetNameByCidr = [];
        foreach (array_keys($allCidrs) as $cidr) {
            $isV6   = str_contains($cidr, ':');
            $subnet = $isV6
                ? $subnetRepo->findOneBy(['ipv6Cidr' => $cidr])
                : $subnetRepo->findOneBy(['ipv4Cidr' => $cidr]);
            $subnetNameByCidr[$cidr] = $subnet?->getName();
        }

        // Load all domains and index by lowercase name for FQDN matching
        $allDomains = $em->getRepository(Domain::class)->findAll();
        usort($allDomains, fn(Domain $a, Domain $b) => strlen($b->getName()) - strlen($a->getName()));
        $domainsSortedByLength = $allDomains; // longest first for best-match

        // Collect all MACs from all entries
        $allMacs = [];
        foreach ($entries as $entry) {
            foreach ($entry['interfaces'] as $iface) {
                if ($iface['mac'] !== '00:00:00:00:00:00') {
                    $allMacs[] = $iface['mac'];
                }
            }
        }
        $ifaceByMac = $interfaceRepo->findByMacs(array_values(array_unique($allMacs)));

        // Collect all IPs for conflict detection
        $allIpv4 = [];
        $allIpv6 = [];
        foreach ($entries as $entry) {
            foreach ($entry['interfaces'] as $iface) {
                if ($iface['ip_address'])   { $allIpv4[] = $iface['ip_address']; }
                if ($iface['ipv6_address']) { $allIpv6[] = $iface['ipv6_address']; }
            }
        }

        $usedIpv4 = [];
        if ($allIpv4) {
            $rows = $ipRepo->createQueryBuilder('ip')
                ->where('ip.address IN (:addrs)')
                ->setParameter('addrs', array_values(array_unique($allIpv4)))
                ->getQuery()->getResult();
            foreach ($rows as $row) {
                $usedIpv4[$row->getAddress()] = true;
            }
        }

        $usedIpv6 = [];
        if ($allIpv6) {
            $rows = $ipv6Repo->createQueryBuilder('ip')
                ->where('ip.address IN (:addrs)')
                ->setParameter('addrs', array_values(array_unique($allIpv6)))
                ->getQuery()->getResult();
            foreach ($rows as $row) {
                $usedIpv6[$row->getAddress()] = true;
            }
        }

        $seenIpv4 = [];
        $seenIpv6 = [];
        $seenMacs = [];

        $preview = ['hosts' => []];

        foreach ($entries as $entry) {
            $hostname = $entry['hostname'];

            if (isset($existingHostNames[$hostname])) {
                $preview['hosts'][] = [
                    'hostname'        => $hostname,
                    'building_name'   => $entry['building_name'],
                    'room'            => $entry['room'],
                    'notes'           => $entry['notes'],
                    'tags'            => $entry['tags'],
                    'unknown_building' => false,
                    'new_tags'         => [],
                    'status'          => 'existing',
                    'interfaces'      => [],
                ];
                continue;
            }

            $unknownBuilding = $entry['building_name'] !== null
                && !isset($buildings[strtolower($entry['building_name'])]);

            $newTags = array_values(array_filter(
                $entry['tags'],
                fn(string $t) => !isset($tags[strtolower($t)])
            ));

            $ifacePreviews = [];
            foreach ($entry['interfaces'] as $iface) {
                $mac     = $iface['mac'];
                $isZero  = ($mac === '00:00:00:00:00:00');

                $subnetName = $iface['subnet_cidr'] ? ($subnetNameByCidr[$iface['subnet_cidr']] ?? null) : null;

                // Non-zero MACs: check for duplicates within this batch
                if (!$isZero && isset($seenMacs[$mac])) {
                    $ifacePreviews[] = array_merge($iface, [
                        'subnet_name'     => $subnetName,
                        'dns_label'       => null,
                        'dns_domain'      => null,
                        'status'          => 'conflict',
                        'conflict_reason' => 'MAC ' . $mac . ' appears more than once in this file',
                        'existing_host'   => null,
                    ]);
                    continue;
                }

                // Non-zero MACs: check for existing record in DB
                if (!$isZero && isset($ifaceByMac[$mac])) {
                    $ifacePreviews[] = array_merge($iface, [
                        'subnet_name'     => $subnetName,
                        'dns_label'       => null,
                        'dns_domain'      => null,
                        'status'          => 'existing',
                        'conflict_reason' => null,
                        'existing_host'   => $ifaceByMac[$mac]->getHost()?->getName(),
                    ]);
                    $seenMacs[$mac] = true;
                    continue;
                }

                if (!$isZero) {
                    $seenMacs[$mac] = true;
                }
                $conflicts = [];

                if ($iface['subnet_cidr'] && $subnetName === null) {
                    $conflicts[] = 'Subnet "' . $iface['subnet_cidr'] . '" not found';
                }

                $dnsLabel  = null;
                $dnsDomain = null;
                if ($iface['dns_name']) {
                    [$dnsLabel, $dnsDomain] = $this->resolveFqdn($iface['dns_name'], $domainsSortedByLength);
                    if ($dnsLabel === null) {
                        $conflicts[] = 'DNS name "' . $iface['dns_name'] . '" does not match any known domain';
                    }
                }

                if ($iface['ip_address']) {
                    if (isset($usedIpv4[$iface['ip_address']]) || isset($seenIpv4[$iface['ip_address']])) {
                        $conflicts[] = 'IPv4 ' . $iface['ip_address'] . ' already assigned';
                    } else {
                        $seenIpv4[$iface['ip_address']] = true;
                    }
                }
                if ($iface['ipv6_address']) {
                    if (isset($usedIpv6[$iface['ipv6_address']]) || isset($seenIpv6[$iface['ipv6_address']])) {
                        $conflicts[] = 'IPv6 ' . $iface['ipv6_address'] . ' already assigned';
                    } else {
                        $seenIpv6[$iface['ipv6_address']] = true;
                    }
                }

                $ifacePreviews[] = array_merge($iface, [
                    'subnet_name'     => $subnetName,
                    'dns_label'       => $dnsLabel,
                    'dns_domain'      => $dnsDomain,
                    'status'          => $conflicts ? 'conflict' : 'new',
                    'conflict_reason' => $conflicts ? implode('; ', $conflicts) : null,
                    'existing_host'   => null,
                ]);
            }

            $newIfaceCount = count(array_filter($ifacePreviews, fn(array $i) => $i['status'] === 'new'));

            $preview['hosts'][] = [
                'hostname'         => $hostname,
                'building_name'    => $entry['building_name'],
                'room'             => $entry['room'],
                'notes'            => $entry['notes'],
                'tags'             => $entry['tags'],
                'unknown_building' => $unknownBuilding,
                'new_tags'         => $newTags,
                'status'           => $newIfaceCount > 0 ? 'new' : 'no_valid_interfaces',
                'interfaces'       => $ifacePreviews,
            ];
        }

        return $preview;
    }
}
