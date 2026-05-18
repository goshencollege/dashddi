<?php

namespace App\Controller;

use App\Dto\NetworkEvent;
use App\Entity\Domain;
use App\Entity\Host;
use App\Entity\InterfaceName;
use App\Entity\NetworkInterface;
use App\Entity\Subnet;
use App\Form\InterfaceNameType;
use App\Form\NetworkInterfaceType;
use App\Repository\ClearpassAuthLogRepository;
use App\Repository\DhcpLeaseRepository;
use App\Repository\DomainRepository;
use App\Repository\NetworkInterfaceRepository;
use App\Repository\SubnetRepository;
use App\Repository\UserPreferenceRepository;
use App\Service\DnsViewResolver;
use App\Service\FcrdnsChecker;
use App\Service\IpAddressManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class InterfaceController extends AbstractController
{
    public function __construct(
        private readonly IpAddressManager $ipManager,
        private readonly DnsViewResolver  $viewResolver,
        private readonly FcrdnsChecker    $fcrdnsChecker,
    ) {}

    #[Route('/hosts/{id}/interfaces/new', name: 'interface_new', methods: ['GET', 'POST'])]
    public function new(Request $request, Host $host, EntityManagerInterface $em, SubnetRepository $subnetRepo, UserPreferenceRepository $prefRepo): Response
    {
        $user          = $this->getUser();
        $pref          = $user ? $prefRepo->findByIdentifier($user->getUserIdentifier()) : null;
        $subnetChoices = $subnetRepo->buildGroupedChoices($pref?->getSubnetSearch());

        $interface = new NetworkInterface();
        $interface->setHost($host);

        $form = $this->createForm(NetworkInterfaceType::class, $interface, [
            'subnet_choices' => $subnetChoices,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $errors = $this->validateIpInputs($form, $interface->getSubnet(), null);
            if ($errors) {
                foreach ($errors as $error) {
                    $this->addFlash('danger', $error);
                }
            } else {
                $this->handleIpAssignment($form, $interface);
                $em->persist($interface);
                $em->flush();
                $this->addFlash('success', 'Interface added.');
                return $this->redirectToRoute('host_show', ['id' => $host->getId()]);
            }
        }

        return $this->render('interface/form.html.twig', [
            'form'      => $form,
            'interface' => $interface,
            'host'      => $host,
            'title'     => 'Add Interface to ' . $host->getName(),
        ]);
    }

    #[Route('/interfaces/{id}', name: 'interface_show', methods: ['GET'])]
    public function show(NetworkInterface $interface, DhcpLeaseRepository $leaseRepo, ClearpassAuthLogRepository $authLogRepo): Response
    {
        $events = [];

        foreach ($authLogRepo->findByMac($interface->getMacAddress(), 10) as $log) {
            $events[] = new NetworkEvent($log->getAuthTimestamp(), 'auth', $log);
        }
        foreach ($leaseRepo->findByMac($interface->getMacAddress(), 10) as $lease) {
            $events[] = new NetworkEvent($lease->getLeaseStart(), 'dhcp', $lease);
        }

        usort($events, fn(NetworkEvent $a, NetworkEvent $b) => $b->timestamp <=> $a->timestamp);

        return $this->render('interface/show.html.twig', [
            'interface' => $interface,
            'events'    => $events,
        ]);
    }

    #[Route('/interfaces/{id}/edit', name: 'interface_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, NetworkInterface $interface, EntityManagerInterface $em, SubnetRepository $subnetRepo, UserPreferenceRepository $prefRepo): Response
    {
        $user          = $this->getUser();
        $pref          = $user ? $prefRepo->findByIdentifier($user->getUserIdentifier()) : null;
        $subnetChoices = $subnetRepo->buildGroupedChoices($pref?->getSubnetSearch());

        $originalSubnet = $interface->getSubnet();
        $form = $this->createForm(NetworkInterfaceType::class, $interface, [
            'is_edit'        => true,
            'subnet_choices' => $subnetChoices,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $subnetChanged = $originalSubnet !== $interface->getSubnet();
            $errors = $this->validateIpInputs($form, $interface->getSubnet(), $subnetChanged ? null : $interface);
            if ($errors) {
                foreach ($errors as $error) {
                    $this->addFlash('danger', $error);
                }
            } else {
                if ($subnetChanged) {
                    $this->ipManager->releaseIpv4($interface);
                    $this->ipManager->releaseIpv6($interface);
                }
                $this->handleIpAssignment($form, $interface, isEdit: true);
                foreach ($interface->getNames() as $name) {
                    if ($name->isCanonical()) {
                        $fcrdnsError = $this->checkCanonical($name, $interface);
                        if ($fcrdnsError !== null) {
                            $this->addFlash('warning', 'FCrDNS check failed — name saved as canonical anyway. ' . $fcrdnsError);
                        }
                        break;
                    }
                }
                $em->flush();
                $this->addFlash('success', 'Interface updated.');
                return $this->redirectToRoute('interface_show', ['id' => $interface->getId()]);
            }
        }

        return $this->render('interface/form.html.twig', [
            'form'      => $form,
            'interface' => $interface,
            'host'      => $interface->getHost(),
            'title'     => 'Edit Interface',
        ]);
    }

    #[Route('/interfaces/bulk', name: 'interface_bulk', methods: ['POST'])]
    public function bulk(Request $request, NetworkInterfaceRepository $ifaceRepo, EntityManagerInterface $em): JsonResponse
    {
        $data   = json_decode($request->getContent(), true) ?? [];
        $action = $data['action'] ?? '';
        $ids    = array_values(array_filter(array_map('intval', $data['ids'] ?? []), fn($id) => $id > 0));

        if (!$this->isCsrfTokenValid('bulk_interfaces', $data['_token'] ?? '')) {
            return $this->json(['error' => 'Invalid CSRF token'], 403);
        }
        if (empty($ids)) {
            return $this->json(['error' => 'No interfaces selected'], 400);
        }

        if ($action === 'delete') {
            $interfaces = $ifaceRepo->findBy(['id' => $ids]);

            // Group selected interfaces by host so we can detect when all
            // of a host's interfaces are being removed and delete the host instead.
            $byHost = [];
            foreach ($interfaces as $iface) {
                $hid = $iface->getHost()?->getId();
                if ($hid !== null) {
                    $byHost[$hid]['host']       = $iface->getHost();
                    $byHost[$hid]['selected'][] = $iface;
                }
            }

            $count = count($interfaces);
            foreach ($byHost as ['host' => $host, 'selected' => $selected]) {
                if (count($selected) >= $host->getInterfaces()->count()) {
                    $em->remove($host);
                } else {
                    foreach ($selected as $iface) {
                        $em->remove($iface);
                    }
                }
            }
            $em->flush();
            return $this->json(['message' => $count . ' interface(s) deleted.']);
        }

        return $this->json(['error' => 'Unknown action'], 400);
    }

    #[Route('/interfaces/{id}/delete', name: 'interface_delete', methods: ['POST'])]
    public function delete(Request $request, NetworkInterface $interface, EntityManagerInterface $em): Response
    {
        $host   = $interface->getHost();
        $hostId = $host?->getId();
        if ($this->isCsrfTokenValid('delete_interface_' . $interface->getId(), $request->request->get('_token'))) {
            if ($host && $host->getInterfaces()->count() === 1) {
                $em->remove($host);
                $em->flush();
                $this->addFlash('success', 'Interface and host "' . $host->getName() . '" deleted.');
                return $this->redirectToRoute('host_index');
            }
            $em->remove($interface);
            $em->flush();
            $this->addFlash('success', 'Interface deleted.');
        }
        return $this->redirectToRoute('host_show', ['id' => $hostId]);
    }

    #[Route('/interfaces/{id}/names/new', name: 'interface_name_new', methods: ['GET', 'POST'])]
    public function nameNew(Request $request, NetworkInterface $interface, EntityManagerInterface $em): Response
    {
        $name = new InterfaceName();
        $form = $this->createForm(InterfaceNameType::class, $name, ['network_interface' => $interface]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $fcrdnsError = $this->checkCanonical($name, $interface);
            if ($fcrdnsError !== null) {
                $this->addFlash('warning', 'FCrDNS check failed — name saved as canonical anyway. ' . $fcrdnsError);
            }
            if ($name->isCanonical()) {
                $this->clearOtherCanonicals($name, $interface);
            }
            $interface->addName($name);
            $em->persist($name);
            $em->flush();
            $this->addFlash('success', 'Name added.');
            return $this->redirectToRoute('interface_show', ['id' => $interface->getId()]);
        }

        return $this->render('interface/name_form.html.twig', [
            'form'      => $form,
            'interface' => $interface,
            'title'     => 'Add DNS Name',
        ]);
    }

    #[Route('/interfaces/{interfaceId}/names/{id}/edit', name: 'interface_name_edit', methods: ['GET', 'POST'])]
    public function nameEdit(Request $request, int $interfaceId, InterfaceName $name, NetworkInterfaceRepository $repo, EntityManagerInterface $em): Response
    {
        $interface = $repo->find($interfaceId);
        $form = $this->createForm(InterfaceNameType::class, $name, ['network_interface' => $interface]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $fcrdnsError = $this->checkCanonical($name, $interface);
            if ($fcrdnsError !== null) {
                $this->addFlash('warning', 'FCrDNS check failed — name saved as canonical anyway. ' . $fcrdnsError);
            }
            if ($name->isCanonical()) {
                $this->clearOtherCanonicals($name, $interface);
            }
            $em->flush();
            $this->addFlash('success', 'Name updated.');
            return $this->redirectToRoute('interface_show', ['id' => $interfaceId]);
        }

        return $this->render('interface/name_form.html.twig', [
            'form'      => $form,
            'interface' => $interface,
            'title'     => 'Edit DNS Name',
        ]);
    }

    #[Route('/interfaces/{interfaceId}/names/{id}/delete', name: 'interface_name_delete', methods: ['POST'])]
    public function nameDelete(Request $request, int $interfaceId, InterfaceName $name, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_name_' . $name->getId(), $request->request->get('_token'))) {
            $em->remove($name);
            $em->flush();
            $this->addFlash('success', 'Name deleted.');
        }
        return $this->redirectToRoute('interface_show', ['id' => $interfaceId]);
    }

    /** JSON endpoint for the interface form to fetch available IPs when subnet changes. */
    #[Route('/api/subnets/{id}/available-ips', name: 'api_subnet_available_ips', methods: ['GET'])]
    public function availableIps(\App\Entity\Subnet $subnet): JsonResponse
    {
        return $this->json($this->ipManager->getAvailableIpv4($subnet, 100));
    }

    #[Route('/api/subnets/{id}/available-ipv6', name: 'api_subnet_available_ipv6', methods: ['GET'])]
    public function availableIpv6(\App\Entity\Subnet $subnet): JsonResponse
    {
        return $this->json($this->ipManager->getAvailableIpv6($subnet, 50));
    }

    /**
     * Returns views available for a domain, optionally intersected with a subnet's allowed views.
     * Used by the interface name form JS to update view checkboxes on domain change.
     */
    #[Route('/api/domains/{id}/views', name: 'api_domain_views', methods: ['GET'])]
    public function domainViews(Domain $domain, Request $request, SubnetRepository $subnetRepo): JsonResponse
    {
        $subnetId = $request->query->get('subnet');
        $subnet   = $subnetId ? $subnetRepo->find((int) $subnetId) : null;
        $views    = $this->viewResolver->availableViewsFor($domain, $subnet);

        return $this->json(
            array_map(fn($v) => ['id' => $v->getId(), 'name' => $v->getName()], $views)
        );
    }

    /**
     * Returns all domains with a usable flag for the given subnet.
     * Used by the inline name collection JS to update domain disabled state when subnet changes.
     */
    #[Route('/api/subnets/{id}/available-domains', name: 'api_subnet_available_domains', methods: ['GET'])]
    public function subnetAvailableDomains(Subnet $subnet, DomainRepository $domainRepo): JsonResponse
    {
        $domains = $domainRepo->findBy([], ['name' => 'ASC']);

        $result = array_map(function (Domain $domain) use ($subnet) {
            $usable = $this->viewResolver->isDomainUsable($domain, $subnet);
            $entry  = ['id' => $domain->getId(), 'name' => $domain->getName(), 'usable' => $usable];
            if (!$usable) {
                $entry['reason'] = $this->viewResolver->unusableDomainReason($domain, $subnet);
            }
            return $entry;
        }, $domains);

        return $this->json($result);
    }

    private function checkCanonical(InterfaceName $name, NetworkInterface $interface): ?string
    {
        if (!$name->isCanonical()) {
            return null;
        }

        if ($name->getDomain() === null) {
            return 'A domain is required to set a name as canonical — a bare label cannot be used for reverse DNS.';
        }

        return $this->fcrdnsChecker->check(
            $name->getFullyQualifiedName(),
            $interface->getIpAddress()?->getAddress(),
            $interface->getIpv6Address()?->getAddress(),
        );
    }

    private function clearOtherCanonicals(InterfaceName $canonical, NetworkInterface $interface): void
    {
        foreach ($interface->getNames() as $other) {
            if ($other !== $canonical && $other->isCanonical()) {
                $other->setIsCanonical(false);
            }
        }
    }

    private function validateIpInputs(\Symfony\Component\Form\FormInterface $form, ?\App\Entity\Subnet $subnet, ?NetworkInterface $current): array
    {
        $errors = [];

        if ($form->get('ipv4Assignment')->getData() === 'select' && $subnet) {
            $ip = trim((string) $form->get('ipv4AddressInput')->getData());
            if ($ip !== '') {
                $err = $this->ipManager->validateSpecifiedIpv4($ip, $subnet, $current);
                if ($err) {
                    $errors[] = $err;
                }
            }
        }

        if ($form->get('ipv6Assignment')->getData() === 'select' && $subnet) {
            $ip = trim((string) $form->get('ipv6AddressInput')->getData());
            if ($ip !== '') {
                $err = $this->ipManager->validateSpecifiedIpv6($ip, $subnet, $current);
                if ($err) {
                    $errors[] = $err;
                }
            }
        }

        return $errors;
    }

    private function handleIpAssignment(\Symfony\Component\Form\FormInterface $form, NetworkInterface $interface, bool $isEdit = false): void
    {
        $subnet = $interface->getSubnet();

        $ipv4Mode = $form->get('ipv4Assignment')->getData();
        if ($ipv4Mode !== 'keep') {
            if ($isEdit) $this->ipManager->releaseIpv4($interface);
            if ($ipv4Mode === 'auto' && $subnet?->getIpv4Cidr()) {
                $ip = $this->ipManager->findNextAvailableIpv4($subnet);
                if ($ip) $this->ipManager->assignIpv4($interface, $ip);
            } elseif ($ipv4Mode === 'select') {
                $ip = trim((string) $form->get('ipv4AddressInput')->getData());
                if ($ip !== '' && $subnet) $this->ipManager->assignIpv4($interface, $ip);
            }
        }

        $ipv6Mode = $form->get('ipv6Assignment')->getData();
        if ($ipv6Mode !== 'keep') {
            if ($isEdit) $this->ipManager->releaseIpv6($interface);
            if ($ipv6Mode === 'auto' && $subnet?->getIpv6Cidr()) {
                $ip = $this->ipManager->findNextAvailableIpv6($subnet, $interface->getMacAddress());
                if ($ip) $this->ipManager->assignIpv6($interface, $ip);
            } elseif ($ipv6Mode === 'auto_v4' && $subnet?->getIpv6Cidr()) {
                $ipv4 = $interface->getIpAddress()?->getAddress();
                if ($ipv4) {
                    $ip = $this->ipManager->findIpv6FromIpv4($subnet, $ipv4);
                    if ($ip) $this->ipManager->assignIpv6($interface, $ip);
                }
            } elseif ($ipv6Mode === 'select') {
                $ip = trim((string) $form->get('ipv6AddressInput')->getData());
                if ($ip !== '' && $subnet) $this->ipManager->assignIpv6($interface, $ip);
            }
        }
    }

}
