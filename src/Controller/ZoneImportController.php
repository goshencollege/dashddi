<?php

namespace App\Controller;

use App\Entity\Domain;
use App\Entity\DomainRecord;
use App\Entity\InterfaceName;
use App\Entity\IpAddress;
use App\Entity\Ipv6Address;
use App\Entity\NetworkInterface;
use App\Enum\RecordType;
use App\Repository\DnsViewRepository;
use App\Repository\IpAddressRepository;
use App\Repository\Ipv6AddressRepository;
use App\Service\BindZoneFileParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/domains/{id}/import', name: 'zone_import_')]
class ZoneImportController extends AbstractController
{
    #[Route('', name: 'upload', methods: ['GET', 'POST'])]
    public function upload(
        Domain $domain,
        Request $request,
        BindZoneFileParser $parser,
        DnsViewRepository $viewRepo,
        IpAddressRepository $ipRepo,
        Ipv6AddressRepository $ipv6Repo,
        EntityManagerInterface $em,
    ): Response {
        $allViews = $viewRepo->findBy([], ['name' => 'ASC']);

        if (!$request->isMethod('POST')) {
            return $this->render('zone_import/upload.html.twig', [
                'domain'    => $domain,
                'all_views' => $allViews,
            ]);
        }

        $file = $request->files->get('zone_file');
        if (!$file || !$file->isValid()) {
            $this->addFlash('danger', 'Please select a valid file to upload.');
            return $this->redirectToRoute('zone_import_upload', ['id' => $domain->getId()]);
        }

        $content = file_get_contents($file->getPathname());
        if ($content === false || $content === '') {
            $this->addFlash('danger', 'The uploaded file appears to be empty.');
            return $this->redirectToRoute('zone_import_upload', ['id' => $domain->getId()]);
        }

        $parsed = $parser->parse($content, $domain->getName());

        if (!empty($parsed['errors'])) {
            foreach ($parsed['errors'] as $error) {
                $this->addFlash('danger', 'Parse error: ' . $error);
            }
            return $this->redirectToRoute('zone_import_upload', ['id' => $domain->getId()]);
        }

        if (empty($parsed['records'])) {
            $this->addFlash('warning', 'No supported DNS records were found in the uploaded file.');
            return $this->redirectToRoute('zone_import_upload', ['id' => $domain->getId()]);
        }

        $allPost = $request->request->all();
        $viewIds = array_map('intval', (array) ($allPost['view_ids'] ?? []));

        $preview = $this->buildPreview($parsed['records'], $domain, $viewIds, $ipRepo, $ipv6Repo, $em);

        $request->getSession()->set('zone_import', $preview);

        return $this->redirectToRoute('zone_import_preview', ['id' => $domain->getId()]);
    }

    #[Route('/preview', name: 'preview', methods: ['GET'])]
    public function preview(Domain $domain, Request $request, DnsViewRepository $viewRepo): Response
    {
        $preview = $request->getSession()->get('zone_import');
        if (!$preview || $preview['domain_id'] !== $domain->getId()) {
            $this->addFlash('warning', 'No import data found. Please upload a zone file first.');
            return $this->redirectToRoute('zone_import_upload', ['id' => $domain->getId()]);
        }

        $views = [];
        foreach ($preview['view_ids'] as $viewId) {
            $view = $viewRepo->find($viewId);
            if ($view) {
                $views[] = $view;
            }
        }

        return $this->render('zone_import/preview.html.twig', [
            'domain'  => $domain,
            'preview' => $preview,
            'views'   => $views,
        ]);
    }

    #[Route('/confirm', name: 'confirm', methods: ['POST'])]
    public function confirm(
        Domain $domain,
        Request $request,
        EntityManagerInterface $em,
        DnsViewRepository $viewRepo,
    ): Response {
        if (!$this->isCsrfTokenValid('zone_import_confirm_' . $domain->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('zone_import_preview', ['id' => $domain->getId()]);
        }

        $preview = $request->getSession()->get('zone_import');
        if (!$preview || $preview['domain_id'] !== $domain->getId()) {
            $this->addFlash('warning', 'Session expired. Please upload the file again.');
            return $this->redirectToRoute('zone_import_upload', ['id' => $domain->getId()]);
        }

        $views = [];
        foreach ($preview['view_ids'] as $viewId) {
            $view = $viewRepo->find($viewId);
            if ($view) {
                $views[] = $view;
            }
        }

        $recordsCreated   = 0;
        $hostNamesCreated = 0;
        $viewsUpdated     = 0;

        foreach ($preview['records'] as $r) {
            if ($r['action'] === 'skip') {
                continue;
            }

            if ($r['action'] === 'host_dns_name') {
                $iface = $em->find(NetworkInterface::class, $r['interface_id']);
                if (!$iface) {
                    continue;
                }

                $ifaceName = new InterfaceName();
                $ifaceName->setName($r['name']);
                $ifaceName->setDomain($domain);
                $ifaceName->setNetworkInterface($iface);
                $ifaceName->setTtl($r['ttl']);
                foreach ($views as $view) {
                    $ifaceName->addView($view);
                }
                $em->persist($ifaceName);
                $hostNamesCreated++;
                continue;
            }

            if ($r['action'] === 'dns_record') {
                $recordType = RecordType::tryFrom($r['type']);
                if ($recordType === null) {
                    continue;
                }

                $record = new DomainRecord();
                $record->setDomain($domain);
                $record->setHostname($r['name']);
                $record->setType($recordType);
                $record->setValue($r['value']);
                $record->setTtl($r['ttl']);
                $record->setComment($r['comment'] ?? null);
                foreach ($views as $view) {
                    $record->addView($view);
                }
                $em->persist($record);
                $recordsCreated++;
                continue;
            }

            if ($r['action'] === 'add_view') {
                if ($r['existing_record_id'] !== null) {
                    $record = $em->find(DomainRecord::class, $r['existing_record_id']);
                    if ($record) {
                        foreach ($views as $view) {
                            $record->addView($view);
                        }
                        $viewsUpdated++;
                    }
                } elseif ($r['existing_iface_name_id'] !== null) {
                    $ifaceName = $em->find(InterfaceName::class, $r['existing_iface_name_id']);
                    if ($ifaceName) {
                        foreach ($views as $view) {
                            $ifaceName->addView($view);
                        }
                        $viewsUpdated++;
                    }
                }
            }
        }

        if ($recordsCreated > 0 || $hostNamesCreated > 0 || $viewsUpdated > 0) {
            $em->flush();
        }

        $request->getSession()->remove('zone_import');

        if ($recordsCreated || $hostNamesCreated || $viewsUpdated) {
            $this->addFlash('success', sprintf(
                'Import complete: %d DNS record(s) and %d host DNS name(s) created, %d existing record(s) updated with new view(s).',
                $recordsCreated,
                $hostNamesCreated,
                $viewsUpdated
            ));
        } else {
            $this->addFlash('info', 'Nothing new to import — all records already existed and were skipped.');
        }

        return $this->redirectToRoute('domain_show', ['id' => $domain->getId()]);
    }

    private function buildPreview(
        array $parsedRecords,
        Domain $domain,
        array $viewIds,
        IpAddressRepository $ipRepo,
        Ipv6AddressRepository $ipv6Repo,
        EntityManagerInterface $em,
    ): array {
        $preview = [
            'domain_id' => $domain->getId(),
            'view_ids'  => $viewIds,
            'records'   => [],
        ];

        // Gather IPs for batch lookup
        $aValues    = [];
        $aaaaValues = [];
        foreach ($parsedRecords as $r) {
            if ($r['type'] === 'A') {
                $aValues[] = $r['value'];
            } elseif ($r['type'] === 'AAAA') {
                $aaaaValues[] = $r['value'];
            }
        }

        // Batch-load IpAddress entities
        $ipByAddress = [];
        if ($aValues) {
            $rows = $ipRepo->createQueryBuilder('ip')
                ->where('ip.address IN (:addrs)')
                ->setParameter('addrs', $aValues)
                ->getQuery()->getResult();
            foreach ($rows as $ip) {
                $ipByAddress[$ip->getAddress()] = $ip;
            }
        }

        // Batch-load Ipv6Address entities
        $ipv6ByAddress = [];
        if ($aaaaValues) {
            $rows = $ipv6Repo->createQueryBuilder('ip')
                ->where('ip.address IN (:addrs)')
                ->setParameter('addrs', $aaaaValues)
                ->getQuery()->getResult();
            foreach ($rows as $ip) {
                $ipv6ByAddress[$ip->getAddress()] = $ip;
            }
        }

        // Batch-load NetworkInterfaces by IpAddress
        $ifaceByIpId = [];
        if ($ipByAddress) {
            $ifaces = $em->createQueryBuilder()
                ->select('ni')
                ->from(NetworkInterface::class, 'ni')
                ->where('ni.ipAddress IN (:ips)')
                ->setParameter('ips', array_values($ipByAddress))
                ->getQuery()->getResult();
            foreach ($ifaces as $iface) {
                $ifaceByIpId[$iface->getIpAddress()->getId()] = $iface;
            }
        }

        // Batch-load NetworkInterfaces by Ipv6Address
        $ifaceByIpv6Id = [];
        if ($ipv6ByAddress) {
            $ifaces = $em->createQueryBuilder()
                ->select('ni')
                ->from(NetworkInterface::class, 'ni')
                ->where('ni.ipv6Address IN (:ips)')
                ->setParameter('ips', array_values($ipv6ByAddress))
                ->getQuery()->getResult();
            foreach ($ifaces as $iface) {
                $ifaceByIpv6Id[$iface->getIpv6Address()->getId()] = $iface;
            }
        }

        // Index existing DomainRecords: key → ['id' => int, 'view_ids' => int[]]
        $existingRecords = [];
        foreach ($domain->getRecords() as $record) {
            $key = strtolower($record->getHostname()) . '|' . $record->getType()->value . '|' . $record->getValue();
            $existingRecords[$key] = [
                'id'       => $record->getId(),
                'view_ids' => array_map(fn($v) => $v->getId(), $record->getViews()->toArray()),
            ];
        }

        // Index existing InterfaceNames on this domain: key → ['id' => int, 'view_ids' => int[]]
        $existingIfaceNames = [];
        foreach ($domain->getInterfaceNames() as $ifaceName) {
            $ifaceId = $ifaceName->getNetworkInterface()->getId();
            $key     = $ifaceId . '|' . strtolower($ifaceName->getName());
            $existingIfaceNames[$key] = [
                'id'       => $ifaceName->getId(),
                'view_ids' => array_map(fn($v) => $v->getId(), $ifaceName->getViews()->toArray()),
            ];
        }

        foreach ($parsedRecords as $r) {
            $entry = [
                'name'                   => $r['name'],
                'type'                   => $r['type'],
                'value'                  => $r['value'],
                'ttl'                    => $r['ttl'],
                'comment'                => $r['comment'] ?? null,
                'action'                 => 'dns_record',
                'skip_reason'            => null,
                'host_name'              => null,
                'interface_id'           => null,
                'existing_record_id'     => null,
                'existing_iface_name_id' => null,
            ];

            if ($r['type'] === 'A' && $this->isSimpleLabel($r['name'])) {
                $ip    = $ipByAddress[$r['value']] ?? null;
                $iface = $ip ? ($ifaceByIpId[$ip->getId()] ?? null) : null;
                if ($iface) {
                    $key      = $iface->getId() . '|' . strtolower($r['name']);
                    $existing = $existingIfaceNames[$key] ?? null;
                    if ($existing !== null) {
                        $missingViews = array_diff($viewIds, $existing['view_ids']);
                        if (!empty($missingViews)) {
                            $entry['action']                 = 'add_view';
                            $entry['existing_iface_name_id'] = $existing['id'];
                            $entry['host_name']              = $iface->getHost()?->getName();
                        } else {
                            $entry['action']      = 'skip';
                            $entry['skip_reason'] = 'Host DNS name already exists in selected view(s)';
                        }
                    } else {
                        $entry['action']       = 'host_dns_name';
                        $entry['interface_id'] = $iface->getId();
                        $entry['host_name']    = $iface->getHost()?->getName();
                    }
                }
            } elseif ($r['type'] === 'AAAA' && $this->isSimpleLabel($r['name'])) {
                $ip6   = $ipv6ByAddress[$r['value']] ?? null;
                $iface = $ip6 ? ($ifaceByIpv6Id[$ip6->getId()] ?? null) : null;
                if ($iface) {
                    $key      = $iface->getId() . '|' . strtolower($r['name']);
                    $existing = $existingIfaceNames[$key] ?? null;
                    if ($existing !== null) {
                        $missingViews = array_diff($viewIds, $existing['view_ids']);
                        if (!empty($missingViews)) {
                            $entry['action']                 = 'add_view';
                            $entry['existing_iface_name_id'] = $existing['id'];
                            $entry['host_name']              = $iface->getHost()?->getName();
                        } else {
                            $entry['action']      = 'skip';
                            $entry['skip_reason'] = 'Host DNS name already exists in selected view(s)';
                        }
                    } else {
                        $entry['action']       = 'host_dns_name';
                        $entry['interface_id'] = $iface->getId();
                        $entry['host_name']    = $iface->getHost()?->getName();
                    }
                }
            }

            if ($entry['action'] === 'dns_record') {
                $key      = strtolower($r['name']) . '|' . $r['type'] . '|' . $r['value'];
                $existing = $existingRecords[$key] ?? null;
                if ($existing !== null) {
                    $missingViews = array_diff($viewIds, $existing['view_ids']);
                    if (!empty($missingViews)) {
                        $entry['action']             = 'add_view';
                        $entry['existing_record_id'] = $existing['id'];
                    } else {
                        $entry['action']      = 'skip';
                        $entry['skip_reason'] = 'Record already exists in selected view(s)';
                    }
                }
            }

            $preview['records'][] = $entry;
        }

        return $preview;
    }

    private function isSimpleLabel(string $name): bool
    {
        return $name !== '@'
            && !str_contains($name, '.')
            && preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?$/', $name) === 1;
    }
}
