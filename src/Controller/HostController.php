<?php

namespace App\Controller;

use App\Entity\Host;
use App\Form\HostType;
use App\Repository\BuildingRepository;
use App\Repository\DhcpLeaseRepository;
use App\Repository\HostRepository;
use App\Repository\SubnetRepository;
use App\Repository\TagRepository;
use App\Repository\UserPreferenceRepository;
use App\Service\IpAddressManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/hosts')]
class HostController extends AbstractController
{
    public function __construct(
        private readonly IpAddressManager $ipManager,
    ) {}

    private const PER_PAGE = 50;

    #[Route('', name: 'host_index', methods: ['GET'])]
    public function index(Request $request, HostRepository $repo, SubnetRepository $subnetRepo, BuildingRepository $buildingRepo, TagRepository $tagRepo, UserPreferenceRepository $prefRepo, DhcpLeaseRepository $leaseRepo): Response
    {
        $page  = max(1, $request->query->getInt('page', 1));
        $query = trim($request->query->getString('q'));

        $advancedFields = ['name', 'building', 'room', 'subnet', 'ip', 'mac', 'dns', 'tag'];
        $criteria = [];
        foreach ($advancedFields as $field) {
            $val = trim($request->query->getString($field));
            if ($val !== '') {
                $criteria[$field] = $val;
            }
        }
        $isAdvanced = !empty($criteria);

        if ($isAdvanced) {
            ['hosts' => $hosts, 'total' => $total] = $repo->advancedSearchPaginated($criteria, $page, self::PER_PAGE);
        } elseif ($query !== '') {
            ['hosts' => $hosts, 'total' => $total] = $repo->searchPaginated($query, $page, self::PER_PAGE);
        } else {
            ['hosts' => $hosts, 'total' => $total] = $repo->findAllPaginated($page, self::PER_PAGE);
        }

        $user = $this->getUser();
        $pref = $user ? $prefRepo->findByIdentifier($user->getUserIdentifier()) : null;
        $hostViewMode = $pref?->getHostViewMode() ?? 'host';

        $macs = [];
        foreach ($hosts as $host) {
            foreach ($host->getInterfaces() as $iface) {
                $macs[] = $iface->getMacAddress();
            }
        }

        // Params for pagination link generation (everything except 'page')
        $linkParams = array_filter([
            'q'        => $query ?: null,
            'name'     => $criteria['name'] ?? null,
            'building' => $criteria['building'] ?? null,
            'room'     => $criteria['room'] ?? null,
            'subnet'   => $criteria['subnet'] ?? null,
            'ip'       => $criteria['ip'] ?? null,
            'mac'      => $criteria['mac'] ?? null,
            'dns'      => $criteria['dns'] ?? null,
            'tag'      => $criteria['tag'] ?? null,
        ]);

        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));

        return $this->render('host/index.html.twig', [
            'hosts'        => $hosts,
            'query'        => $query,
            'criteria'     => $criteria,
            'isAdvanced'   => $isAdvanced,
            'subnets'      => $subnetRepo->findBy([], ['name' => 'ASC']),
            'buildings'    => $buildingRepo->findBy([], ['name' => 'ASC']),
            'tags'         => $tagRepo->findBy([], ['name' => 'ASC']),
            'hostViewMode' => $hostViewMode,
            'lease_map'    => $leaseRepo->findLatestByMacs($macs),
            'pagination'   => [
                'page'       => $page,
                'per_page'   => self::PER_PAGE,
                'total'      => $total,
                'pages'      => $totalPages,
                'link_params'=> $linkParams,
            ],
        ]);
    }

    #[Route('/new', name: 'host_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, SubnetRepository $subnetRepo, UserPreferenceRepository $prefRepo): Response
    {
        $user          = $this->getUser();
        $pref          = $user ? $prefRepo->findByIdentifier($user->getUserIdentifier()) : null;
        $subnetChoices = $subnetRepo->buildGroupedChoices($pref?->getSubnetSearch());

        $host = new Host();
        $form = $this->createForm(HostType::class, $host, [
            'embed_interface' => true,
            'subnet_choices'  => $subnetChoices,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $ifaceForm = $form->get('interface');
            $interface = $ifaceForm->getData();
            $subnet    = $interface->getSubnet();

            $errors = [];
            if ($ifaceForm->get('ipv4Assignment')->getData() === 'select' && $subnet) {
                $ip = trim((string) $ifaceForm->get('ipv4AddressInput')->getData());
                if ($ip !== '') {
                    $err = $this->ipManager->validateSpecifiedIpv4($ip, $subnet);
                    if ($err) {
                        $errors[] = $err;
                    }
                }
            }
            if ($ifaceForm->get('ipv6Assignment')->getData() === 'select' && $subnet) {
                $ip = trim((string) $ifaceForm->get('ipv6AddressInput')->getData());
                if ($ip !== '') {
                    $err = $this->ipManager->validateSpecifiedIpv6($ip, $subnet);
                    if ($err) {
                        $errors[] = $err;
                    }
                }
            }

            if ($errors) {
                foreach ($errors as $error) {
                    $this->addFlash('danger', $error);
                }
            } else {
                $interface->setHost($host);
                $this->assignIps($ifaceForm, $interface);

                $em->persist($host);
                $em->persist($interface);
                $em->flush();
                $this->addFlash('success', 'Host "' . $host->getName() . '" created.');
                return $this->redirectToRoute('host_show', ['id' => $host->getId()]);
            }
        }

        return $this->render('host/form.html.twig', [
            'form'            => $form,
            'host'            => $host,
            'title'           => 'New Host',
            'embed_interface' => true,
        ]);
    }

    #[Route('/{id}', name: 'host_show', methods: ['GET'])]
    public function show(Host $host, DhcpLeaseRepository $leaseRepo): Response
    {
        $macs = array_map(
            fn($i) => $i->getMacAddress(),
            $host->getInterfaces()->toArray()
        );
        return $this->render('host/show.html.twig', [
            'host'      => $host,
            'lease_map' => $leaseRepo->findLatestByMacs($macs),
        ]);
    }

    #[Route('/{id}/edit', name: 'host_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Host $host, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(HostType::class, $host);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Host updated.');
            return $this->redirectToRoute('host_show', ['id' => $host->getId()]);
        }

        return $this->render('host/form.html.twig', [
            'form'            => $form,
            'host'            => $host,
            'title'           => 'Edit Host: ' . $host->getName(),
            'embed_interface' => false,
        ]);
    }

    #[Route('/{id}/delete', name: 'host_delete', methods: ['POST'])]
    public function delete(Request $request, Host $host, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_host_' . $host->getId(), $request->request->get('_token'))) {
            $em->remove($host);
            $em->flush();
            $this->addFlash('success', 'Host deleted.');
        }
        return $this->redirectToRoute('host_index');
    }

    private function assignIps(\Symfony\Component\Form\FormInterface $ifaceForm, \App\Entity\NetworkInterface $interface): void
    {
        $subnet = $interface->getSubnet();

        $ipv4Mode = $ifaceForm->get('ipv4Assignment')->getData();
        if ($ipv4Mode === 'auto' && $subnet?->getIpv4Cidr()) {
            $ip = $this->ipManager->findNextAvailableIpv4($subnet);
            if ($ip) {
                $this->ipManager->assignIpv4($interface, $ip);
            }
        } elseif ($ipv4Mode === 'select') {
            $ip = trim((string) $ifaceForm->get('ipv4AddressInput')->getData());
            if ($ip !== '' && $subnet) {
                $this->ipManager->assignIpv4($interface, $ip);
            }
        }

        $ipv6Mode = $ifaceForm->get('ipv6Assignment')->getData();
        if ($ipv6Mode === 'auto' && $subnet?->getIpv6Cidr()) {
            $ip = $this->ipManager->findNextAvailableIpv6($subnet, $interface->getMacAddress());
            if ($ip) {
                $this->ipManager->assignIpv6($interface, $ip);
            }
        } elseif ($ipv6Mode === 'auto_v4' && $subnet?->getIpv6Cidr()) {
            $ipv4 = $interface->getIpAddress()?->getAddress();
            if ($ipv4) {
                $ip = $this->ipManager->findIpv6FromIpv4($subnet, $ipv4);
                if ($ip) {
                    $this->ipManager->assignIpv6($interface, $ip);
                }
            }
        } elseif ($ipv6Mode === 'select') {
            $ip = trim((string) $ifaceForm->get('ipv6AddressInput')->getData());
            if ($ip !== '' && $subnet) {
                $this->ipManager->assignIpv6($interface, $ip);
            }
        }
    }
}
