<?php

namespace App\Controller;

use App\Entity\DnssecDisableRequest;
use App\Entity\Domain;
use App\Entity\Subnet;
use App\Enum\DnssecDisableStatus;
use App\Form\DnssecDisableStartType;
use App\Repository\DnssecDisableRequestRepository;
use App\Repository\DnssecKskRolloverRepository;
use App\Repository\DnsServerRepository;
use App\Service\DnssecDisableService;
use App\Service\KskRolloverService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dnssec/disable')]
class DnssecDisableController extends AbstractController
{
    #[Route('', name: 'dnssec_disable_index', methods: ['GET'])]
    public function index(DnssecDisableRequestRepository $repo): Response
    {
        return $this->render('dnssec_disable/index.html.twig', [
            'requests' => $repo->findBy([], ['startedAt' => 'DESC']),
        ]);
    }

    #[Route('/start', name: 'dnssec_disable_start', methods: ['GET', 'POST'])]
    public function start(
        Request $request,
        EntityManagerInterface $em,
        DnssecDisableService $svc,
        KskRolloverService $kskSvc,
        DnssecDisableRequestRepository $repo,
        DnssecKskRolloverRepository $kskRepo,
        DnsServerRepository $serverRepo,
    ): Response {
        $firstServer  = $serverRepo->findOneBy([], ['name' => 'ASC']);
        $zoneChoices  = $this->buildZoneChoices($em);
        $flatValues   = array_merge(...array_values($zoneChoices ?: [[]]));

        $defaultZone = null;
        if ($domainId = $request->query->getInt('domain')) {
            $candidate = 'domain:' . $domainId;
            if (in_array($candidate, $flatValues, true)) {
                $defaultZone = $candidate;
            }
        } elseif ($subnetId = $request->query->getInt('subnet')) {
            $candidate = 'subnet:' . $subnetId;
            if (in_array($candidate, $flatValues, true)) {
                $defaultZone = $candidate;
            }
        }

        $form = $this->createForm(DnssecDisableStartType::class, null, [
            'first_server' => $firstServer,
            'zone_choices' => $zoneChoices,
            'default_zone' => $defaultZone,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data      = $form->getData();
            $zoneValue = $data['zone'];   // 'domain:N' or 'subnet:N'
            $server    = $data['dnsServer'];

            [$type, $id] = explode(':', $zoneValue, 2);
            $domain = $subnet = null;
            if ($type === 'domain') {
                $domain = $em->find(Domain::class, (int) $id);
            } else {
                $subnet = $em->find(Subnet::class, (int) $id);
            }

            if (!$domain && !$subnet) {
                $this->addFlash('danger', 'Selected zone no longer exists.');
                return $this->redirectToRoute('dnssec_disable_start');
            }

            $disableRequest = new DnssecDisableRequest();
            if ($domain) {
                if ($repo->findActiveForDomain($domain)) {
                    $this->addFlash('danger', 'An active DNSSEC disable request already exists for "' . $domain->getName() . '".');
                    return $this->redirectToRoute('dnssec_disable_index');
                }
                if ($kskRepo->findActiveForDomain($domain)) {
                    $this->addFlash('danger', 'An active KSK rollover is in progress for "' . $domain->getName() . '". Finish or fail it before disabling DNSSEC.');
                    return $this->redirectToRoute('ksk_rollover_index');
                }
                $disableRequest->setDomain($domain);
            } else {
                if ($repo->findActiveForSubnet($subnet)) {
                    $this->addFlash('danger', 'An active DNSSEC disable request already exists for subnet "' . $subnet->getName() . '".');
                    return $this->redirectToRoute('dnssec_disable_index');
                }
                if ($kskRepo->findActiveForSubnet($subnet)) {
                    $this->addFlash('danger', 'An active KSK rollover is in progress for subnet "' . $subnet->getName() . '". Finish or fail it before disabling DNSSEC.');
                    return $this->redirectToRoute('ksk_rollover_index');
                }
                $disableRequest->setSubnet($subnet);
            }

            $disableRequest->setDnsServer($server);

            // Persist first so the record exists in DB before any SSH work.
            // This keeps the EntityManager clean: SSH exceptions are not DB exceptions
            // and cannot close the EM, so the subsequent flush always succeeds.
            $em->persist($disableRequest);
            $em->flush();

            try {
                $dsRecords = $domain
                    ? $kskSvc->fetchCurrentDsRecords($domain, $server)
                    : $kskSvc->fetchCurrentDsRecordsForSubnet($subnet, $server);

                $disableRequest->setDsRecordsAtStart(implode("\n", $dsRecords));
                $disableRequest->addLog(sprintf('Captured %d existing DS record(s) for registrar reference.', count($dsRecords)));
                $this->addFlash('success', 'Disable request created for "' . $disableRequest->getZoneName() . '". Remove the DS record(s) shown below at your registrar.');
            } catch (\Throwable $e) {
                $disableRequest->setStatus(DnssecDisableStatus::Failed);
                $disableRequest->addLog('Error: ' . $e->getMessage());
                $disableRequest->setCompletedAt(new \DateTimeImmutable());
                $this->addFlash('danger', 'Could not read current DS records: ' . $e->getMessage());
            }

            $em->flush();

            return $this->redirectToRoute('dnssec_disable_show', ['id' => $disableRequest->getId()]);
        }

        return $this->render('dnssec_disable/start.html.twig', [
            'form' => $form,
        ]);
    }

    /** @return array<string, array<string, string>> */
    private function buildZoneChoices(EntityManagerInterface $em): array
    {
        $choices = [];

        $domains = $em->getRepository(Domain::class)
            ->createQueryBuilder('d')
            ->where('d.dnssecPolicy IS NOT NULL')
            ->orderBy('d.name', 'ASC')
            ->getQuery()->getResult();

        foreach ($domains as $d) {
            $choices['Forward Zones'][$d->getName()] = 'domain:' . $d->getId();
        }

        $subnets = $em->getRepository(Subnet::class)
            ->createQueryBuilder('s')
            ->where('s.dnssecPolicy IS NOT NULL')
            ->orderBy('s.name', 'ASC')
            ->getQuery()->getResult();

        foreach ($subnets as $s) {
            $label  = $s->getName();
            $label .= $s->getIpv4Cidr() ? ' (' . $s->getIpv4Cidr() . ')' : '';
            $label .= $s->getIpv6Cidr() ? ' (' . $s->getIpv6Cidr() . ')' : '';
            $label .= $s->getReverseZoneName() ? ' → ' . $s->getReverseZoneName() : '';
            $choices['Reverse Zones'][$label] = 'subnet:' . $s->getId();
        }

        return $choices;
    }

    #[Route('/{id}', name: 'dnssec_disable_show', methods: ['GET'])]
    public function show(DnssecDisableRequest $disableRequest): Response
    {
        return $this->render('dnssec_disable/show.html.twig', ['disableRequest' => $disableRequest]);
    }

    #[Route('/{id}/advance', name: 'dnssec_disable_advance', methods: ['POST'])]
    public function advance(Request $request, DnssecDisableRequest $disableRequest, EntityManagerInterface $em, DnssecDisableService $svc): Response
    {
        if (!$this->isCsrfTokenValid('dnssec_disable_advance_' . $disableRequest->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('dnssec_disable_show', ['id' => $disableRequest->getId()]);
        }

        $action = $request->request->get('action');

        try {
            match ($action) {
                'ds_removed'   => $this->transition($disableRequest, DnssecDisableStatus::DsRemoved, 'DS record(s) removed from registrar; waiting for propagation.', $em),
                'retire_keys'  => $this->retireKeys($disableRequest, $svc),
                'complete'     => $this->complete($disableRequest, $em),
                'fail'         => $this->failRequest($disableRequest, $em),
                default        => throw new \InvalidArgumentException("Unknown action: $action"),
            };
            // retire_keys updates entity without flushing; flush here covers it.
            // transition()/complete()/failRequest() call flush themselves, so this is a harmless no-op for those.
            $em->flush();
        } catch (\Throwable $e) {
            $disableRequest->addLog('Error: ' . $e->getMessage());
            $em->flush();
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('dnssec_disable_show', ['id' => $disableRequest->getId()]);
    }

    // -------------------------------------------------------------------------

    private function transition(DnssecDisableRequest $disableRequest, DnssecDisableStatus $status, string $logMsg, EntityManagerInterface $em): void
    {
        $disableRequest->setStatus($status);
        $disableRequest->addLog($logMsg);
        $em->flush();
    }

    private function retireKeys(DnssecDisableRequest $disableRequest, DnssecDisableService $svc): void
    {
        $svc->retireAllKeys($disableRequest); // SSH only; entity updated but not flushed — caller flushes
        $this->addFlash('success', 'Keys retired. BIND will drop signatures on its next zone maintenance cycle.');
    }

    private function complete(DnssecDisableRequest $disableRequest, EntityManagerInterface $em): void
    {
        $domain = $disableRequest->getDomain();
        $subnet = $disableRequest->getSubnet();
        $domain?->setDnssecPolicy(null);
        $subnet?->setDnssecPolicy(null);

        $disableRequest->setStatus(DnssecDisableStatus::Complete);
        $disableRequest->addLog('DNSSEC policy cleared from ' . ($domain ? 'domain' : 'subnet') . '. Push DNS config to remove the zone\'s signing configuration.');
        $disableRequest->setCompletedAt(new \DateTimeImmutable());
        $em->flush();

        $this->addFlash('success', 'DNSSEC disabled. Push DNS config from the Servers page to apply the change.');
    }

    private function failRequest(DnssecDisableRequest $disableRequest, EntityManagerInterface $em): void
    {
        $disableRequest->setStatus(DnssecDisableStatus::Failed);
        $disableRequest->addLog('Disable request marked as failed.');
        $disableRequest->setCompletedAt(new \DateTimeImmutable());
        $em->flush();
    }
}
