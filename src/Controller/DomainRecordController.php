<?php

namespace App\Controller;

use App\Entity\Domain;
use App\Entity\DomainRecord;
use App\Entity\NetworkInterface;
use App\Entity\VirtualIp;
use App\Enum\RecordType;
use App\Form\DomainRecordType;
use App\Repository\DomainRecordRepository;
use App\Repository\NetworkInterfaceRepository;
use App\Service\DnsViewResolver;
use App\Service\FcrdnsChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DomainRecordController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly FcrdnsChecker               $fcrdnsChecker,
        private readonly DomainRecordRepository      $recordRepo,
        private readonly DnsViewResolver             $viewResolver,
        private readonly NetworkInterfaceRepository  $ifaceRepo,
    ) {}

    #[Route('/domain/{domainId}/records/new', name: 'domain_record_new')]
    public function new(int $domainId, Request $request): Response
    {
        $domain = $this->em->find(Domain::class, $domainId);
        if (!$domain) {
            throw $this->createNotFoundException();
        }

        $record = new DomainRecord();
        $record->setDomain($domain);

        foreach ($domain->getViews() as $view) {
            $record->addView($view);
        }

        $form = $this->createForm(DomainRecordType::class, $record);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($record);
            $this->em->flush();
            $this->addFlash('success', 'Record added.');
            return $this->redirectToRoute('domain_show', ['id' => $domainId]);
        }

        return $this->render('domain_record/form.html.twig', [
            'form'   => $form,
            'domain' => $domain,
            'record' => $record,
        ]);
    }

    #[Route('/interfaces/{id}/dns-records/available-views', name: 'interface_domain_record_views', methods: ['GET'])]
    public function availableViews(NetworkInterface $interface, Request $request): JsonResponse
    {
        $domainId = $request->query->getInt('domain_id');
        $domain   = $domainId ? $this->em->find(Domain::class, $domainId) : null;
        $views    = $domain ? $this->viewResolver->availableViewsFor($domain, $interface->getSubnet()) : [];

        return $this->json(array_map(fn($v) => ['id' => $v->getId(), 'name' => $v->getName()], $views));
    }

    #[Route('/interfaces/{id}/dns-records/new', name: 'interface_domain_record_new', methods: ['GET', 'POST'])]
    public function interfaceNew(Request $request, NetworkInterface $interface): Response
    {
        $record = new DomainRecord();
        $record->setNetworkInterface($interface);

        $form = $this->createForm(DomainRecordType::class, $record, [
            'network_interface' => $interface,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->autoSetCanonical($record);
            $this->normalizeCanonical($record);
            $this->em->persist($record);
            $this->em->flush();

            if ($record->isCanonical()) {
                $this->enforceCanonicalUniqueness($record);
                $fcrdnsError = $this->checkCanonical($record);
                if ($fcrdnsError !== null) {
                    $this->addFlash('warning', 'FCrDNS check failed — record saved as canonical anyway. ' . $fcrdnsError);
                }
            }

            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true]);
            }
            $this->addFlash('success', 'DNS record added.');
            return $this->redirectToRoute('interface_show', ['id' => $interface->getId()]);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'success' => false,
                'html'    => $this->renderView('domain_record/_interface_modal_form.html.twig', [
                    'form'      => $form,
                    'interface' => $interface,
                    'record'    => $record,
                ]),
            ]);
        }

        return $this->render('domain_record/interface_form.html.twig', [
            'form'      => $form,
            'interface' => $interface,
            'record'    => $record,
            'title'     => 'Add DNS Record',
        ]);
    }

    #[Route('/virtual-ips/{id}/dns-records/available-views', name: 'virtual_ip_domain_record_views', methods: ['GET'])]
    public function availableViewsForVip(VirtualIp $virtualIp, Request $request): JsonResponse
    {
        $domainId = $request->query->getInt('domain_id');
        $domain   = $domainId ? $this->em->find(Domain::class, $domainId) : null;
        $views    = $domain ? $this->viewResolver->availableViewsFor($domain, $virtualIp->getSubnet()) : [];

        return $this->json(array_map(fn($v) => ['id' => $v->getId(), 'name' => $v->getName()], $views));
    }

    #[Route('/virtual-ips/{id}/dns-records/new', name: 'virtual_ip_domain_record_new', methods: ['GET', 'POST'])]
    public function virtualIpNew(Request $request, VirtualIp $virtualIp): Response
    {
        $record = new DomainRecord();
        $record->setVirtualIp($virtualIp);

        $form = $this->createForm(DomainRecordType::class, $record, [
            'virtual_ip' => $virtualIp,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->autoSetCanonicalForVip($record);
            $this->normalizeCanonical($record);
            $this->em->persist($record);
            $this->em->flush();

            if ($record->isCanonical()) {
                $this->enforceCanonicalUniquenessForVip($record);
                $fcrdnsError = $this->checkCanonical($record);
                if ($fcrdnsError !== null) {
                    $this->addFlash('warning', 'FCrDNS check failed — record saved as canonical anyway. ' . $fcrdnsError);
                }
            }

            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true]);
            }
            $this->addFlash('success', 'DNS record added.');
            return $this->redirectToRoute('virtual_ip_show', ['id' => $virtualIp->getId()]);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'success' => false,
                'html'    => $this->renderView('domain_record/_virtual_ip_modal_form.html.twig', [
                    'form'      => $form,
                    'virtualIp' => $virtualIp,
                    'record'    => $record,
                ]),
            ]);
        }

        return $this->render('domain_record/virtual_ip_form.html.twig', [
            'form'      => $form,
            'virtualIp' => $virtualIp,
            'record'    => $record,
            'title'     => 'Add DNS Record',
        ]);
    }

    #[Route('/domain-records/{id}/edit', name: 'domain_record_edit')]
    public function edit(DomainRecord $record, Request $request): Response
    {
        $domain    = $record->getDomain();
        $interface = $record->getNetworkInterface();
        $vip       = $record->getVirtualIp();

        $fromVip       = $request->query->get('from') === 'virtual-ip';
        $fromInterface = !$fromVip && ($request->query->get('from') === 'interface' || $request->isXmlHttpRequest());
        $formInterface = $fromInterface ? $interface : null;
        $formVip       = $fromVip ? $vip : null;

        $form = $this->createForm(DomainRecordType::class, $record, [
            'network_interface' => $formInterface,
            'virtual_ip'        => $formVip,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Handle interface linking/unlinking from the domain-context form.
            if ($formInterface === null && $formVip === null) {
                $rawId = $request->request->get('interface_id', '');
                if ($rawId === '' || $rawId === null) {
                    $this->restoreValueFromLinked($record);
                    $record->setNetworkInterface(null);
                } else {
                    $linked = $this->ifaceRepo->find((int) $rawId);
                    if ($linked !== null && !$linked->isDeleted()) {
                        $record->setNetworkInterface($linked);
                        // Clear stored value for A/AAAA so the live IP is used.
                        if (in_array($record->getType()->value, ['A', 'AAAA'], true)) {
                            $record->setValue('');
                        }
                    }
                }
            }

            $this->normalizeCanonical($record);
            $this->em->flush();

            if ($record->isCanonical()) {
                if ($record->getNetworkInterface() !== null) {
                    $this->enforceCanonicalUniqueness($record);
                    $fcrdnsError = $this->checkCanonical($record);
                } elseif ($record->getVirtualIp() !== null) {
                    $this->enforceCanonicalUniquenessForVip($record);
                    $fcrdnsError = $this->checkCanonical($record);
                } else {
                    $fcrdnsError = null;
                }
                if (!empty($fcrdnsError)) {
                    $this->addFlash('warning', 'FCrDNS check failed — record saved as canonical anyway. ' . $fcrdnsError);
                }
            }

            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true]);
            }

            $this->addFlash('success', 'Record updated.');

            if ($fromInterface && $interface !== null) {
                return $this->redirectToRoute('interface_show', ['id' => $interface->getId()]);
            }
            if ($fromVip && $vip !== null) {
                return $this->redirectToRoute('virtual_ip_show', ['id' => $vip->getId()]);
            }
            return $this->redirectToRoute('domain_show', ['id' => $domain->getId()]);
        }

        if ($formInterface !== null && $request->isXmlHttpRequest()) {
            return $this->json([
                'success' => false,
                'html'    => $this->renderView('domain_record/_interface_modal_form.html.twig', [
                    'form'      => $form,
                    'interface' => $formInterface,
                    'record'    => $record,
                ]),
            ]);
        }

        if ($formVip !== null && $request->isXmlHttpRequest()) {
            return $this->json([
                'success' => false,
                'html'    => $this->renderView('domain_record/_virtual_ip_modal_form.html.twig', [
                    'form'      => $form,
                    'virtualIp' => $formVip,
                    'record'    => $record,
                ]),
            ]);
        }

        if ($formInterface !== null) {
            return $this->render('domain_record/interface_form.html.twig', [
                'form'      => $form,
                'interface' => $formInterface,
                'record'    => $record,
                'title'     => 'Edit DNS Record',
            ]);
        }

        if ($formVip !== null) {
            return $this->render('domain_record/virtual_ip_form.html.twig', [
                'form'      => $form,
                'virtualIp' => $formVip,
                'record'    => $record,
                'title'     => 'Edit DNS Record',
            ]);
        }

        return $this->render('domain_record/form.html.twig', [
            'form'   => $form,
            'domain' => $domain,
            'record' => $record,
        ]);
    }

    #[Route('/domain-records/{id}/unlink', name: 'domain_record_unlink', methods: ['POST'])]
    public function unlink(DomainRecord $record, Request $request): Response
    {
        $interfaceId = $record->getNetworkInterface()?->getId();
        $vipId       = $record->getVirtualIp()?->getId();

        if ($this->isCsrfTokenValid('unlink_record_' . $record->getId(), $request->request->get('_token'))) {
            $this->restoreValueFromLinked($record);
            $record->setNetworkInterface(null);
            $record->setVirtualIp(null);
            $this->em->flush();
            $this->addFlash('success', 'Record unlinked.');
        }

        $referer = $request->headers->get('referer', '');
        if ($interfaceId && str_contains($referer, '/interfaces/')) {
            return $this->redirectToRoute('interface_show', ['id' => $interfaceId]);
        }
        if ($vipId && str_contains($referer, '/virtual-ips/')) {
            return $this->redirectToRoute('virtual_ip_show', ['id' => $vipId]);
        }
        return $this->redirectToRoute('domain_show', ['id' => $record->getDomain()->getId()]);
    }

    #[Route('/domain-records/{id}/delete', name: 'domain_record_delete', methods: ['POST'])]
    public function delete(DomainRecord $record, Request $request): Response
    {
        $domainId  = $record->getDomain()->getId();
        $interface = $record->getNetworkInterface();
        $vip       = $record->getVirtualIp();

        if ($this->isCsrfTokenValid('delete_record_' . $record->getId(), $request->request->get('_token'))) {
            $this->em->remove($record);
            $this->em->flush();
            if (!$request->isXmlHttpRequest()) {
                $this->addFlash('success', 'Record deleted.');
            }
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true]);
        }

        if ($interface !== null) {
            return $this->redirectToRoute('interface_show', ['id' => $interface->getId()]);
        }
        if ($vip !== null) {
            return $this->redirectToRoute('virtual_ip_show', ['id' => $vip->getId()]);
        }
        return $this->redirectToRoute('domain_show', ['id' => $domainId]);
    }

    private function autoSetCanonical(DomainRecord $record): void
    {
        $iface = $record->getNetworkInterface();
        if (!$iface) {
            return;
        }
        $type = $record->getType();
        if ($type !== RecordType::A && $type !== RecordType::AAAA) {
            return;
        }
        if (!$this->recordRepo->hasAnyForInterface($iface, $type)) {
            $record->setIsCanonical(true);
        }
    }

    private function autoSetCanonicalForVip(DomainRecord $record): void
    {
        $vip = $record->getVirtualIp();
        if (!$vip) {
            return;
        }
        $type = $record->getType();
        if ($type !== RecordType::A && $type !== RecordType::AAAA) {
            return;
        }
        if (!$this->recordRepo->hasAnyForVirtualIp($vip, $type)) {
            $record->setIsCanonical(true);
        }
    }

    private function normalizeCanonical(DomainRecord $record): void
    {
        $type = $record->getType();
        if ($type !== RecordType::A && $type !== RecordType::AAAA) {
            $record->setIsCanonical(false);
        }
    }

    private function enforceCanonicalUniqueness(DomainRecord $record): void
    {
        if (!$record->isCanonical() || !$record->getNetworkInterface()) {
            return;
        }
        // Load and update via ORM (not a bulk DQL UPDATE) so Doctrine lifecycle
        // events fire and ActivityLogListener records the flag being cleared.
        $previous = $this->recordRepo->findBy([
            'networkInterface' => $record->getNetworkInterface(),
            'type'             => $record->getType(),
            'isCanonical'      => true,
        ]);
        foreach ($previous as $other) {
            if ($other->getId() !== $record->getId()) {
                $other->setIsCanonical(false);
            }
        }
        $this->em->flush();
    }

    private function enforceCanonicalUniquenessForVip(DomainRecord $record): void
    {
        if (!$record->isCanonical() || !$record->getVirtualIp()) {
            return;
        }
        $previous = $this->recordRepo->findBy([
            'virtualIp'  => $record->getVirtualIp(),
            'type'       => $record->getType(),
            'isCanonical' => true,
        ]);
        foreach ($previous as $other) {
            if ($other->getId() !== $record->getId()) {
                $other->setIsCanonical(false);
            }
        }
        $this->em->flush();
    }

    private function restoreValueFromLinked(DomainRecord $record): void
    {
        $iface = $record->getNetworkInterface();
        $vip   = $record->getVirtualIp();
        $type  = $record->getType()->value;

        if ($type === 'A') {
            $ip = $iface?->getIpAddress()?->getAddress() ?? $vip?->getIpAddress()?->getAddress();
        } elseif ($type === 'AAAA') {
            $ip = $iface?->getIpv6Address()?->getAddress() ?? $vip?->getIpv6Address()?->getAddress();
        } else {
            return;
        }
        if ($ip !== null) {
            $record->setValue($ip);
        }
    }

    private function checkCanonical(DomainRecord $record): ?string
    {
        if (!$record->isCanonical()) {
            return null;
        }
        if ($record->getDomain() === null) {
            return 'A domain is required for canonical records.';
        }
        $iface = $record->getNetworkInterface();
        $vip   = $record->getVirtualIp();
        $ipv4  = $iface?->getIpAddress()?->getAddress() ?? $vip?->getIpAddress()?->getAddress();
        $ipv6  = $iface?->getIpv6Address()?->getAddress() ?? $vip?->getIpv6Address()?->getAddress();
        return $this->fcrdnsChecker->check(
            $record->getFullyQualifiedHostname(),
            $ipv4,
            $ipv6,
        );
    }
}
