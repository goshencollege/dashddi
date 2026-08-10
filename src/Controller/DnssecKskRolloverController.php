<?php

namespace App\Controller;

use App\Entity\DnssecKskRollover;
use App\Entity\DnssecPolicy;
use App\Entity\Domain;
use App\Entity\Subnet;
use App\Enum\KskRolloverStatus;
use App\Form\KskRolloverStartType;
use App\Repository\DnssecDisableRequestRepository;
use App\Repository\DnssecKskRolloverRepository;
use App\Repository\DnsServerRepository;
use App\Service\KskRolloverService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dnssec/ksk-rollover')]
class DnssecKskRolloverController extends AbstractController
{
    #[Route('', name: 'ksk_rollover_index', methods: ['GET'])]
    public function index(DnssecKskRolloverRepository $repo): Response
    {
        return $this->render('dnssec_ksk_rollover/index.html.twig', [
            'rollovers' => $repo->findBy([], ['startedAt' => 'DESC']),
        ]);
    }

    #[Route('/start', name: 'ksk_rollover_start', methods: ['GET', 'POST'])]
    public function start(Request $request, EntityManagerInterface $em, KskRolloverService $svc, DnssecKskRolloverRepository $repo, DnssecDisableRequestRepository $disableRepo, DnsServerRepository $serverRepo): Response
    {
        $firstServer    = $serverRepo->findOneBy([], ['name' => 'ASC']);
        [$zoneChoices, $zonePolicyMap] = $this->buildZoneChoices($em);

        $defaultZone = null;
        if ($domainId = $request->query->getInt('domain')) {
            $candidate = 'domain:' . $domainId;
            if (array_key_exists($candidate, $zonePolicyMap)) {
                $defaultZone = $candidate;
            }
        } elseif ($subnetId = $request->query->getInt('subnet')) {
            $candidate = 'subnet:' . $subnetId;
            if (array_key_exists($candidate, $zonePolicyMap)) {
                $defaultZone = $candidate;
            }
        }

        $form = $this->createForm(KskRolloverStartType::class, null, [
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
                return $this->redirectToRoute('ksk_rollover_start');
            }

            $rollover = new DnssecKskRollover();
            if ($domain) {
                if ($repo->findActiveForDomain($domain)) {
                    $this->addFlash('danger', 'An active KSK rollover already exists for "' . $domain->getName() . '".');
                    return $this->redirectToRoute('ksk_rollover_index');
                }
                if ($disableRepo->findActiveForDomain($domain)) {
                    $this->addFlash('danger', 'An active DNSSEC disable request is in progress for "' . $domain->getName() . '". Finish or fail it before starting a rollover.');
                    return $this->redirectToRoute('dnssec_disable_index');
                }
                $rollover->setDomain($domain);
            } else {
                if ($repo->findActiveForSubnet($subnet)) {
                    $this->addFlash('danger', 'An active KSK rollover already exists for subnet "' . $subnet->getName() . '".');
                    return $this->redirectToRoute('ksk_rollover_index');
                }
                if ($disableRepo->findActiveForSubnet($subnet)) {
                    $this->addFlash('danger', 'An active DNSSEC disable request is in progress for subnet "' . $subnet->getName() . '". Finish or fail it before starting a rollover.');
                    return $this->redirectToRoute('dnssec_disable_index');
                }
                $rollover->setSubnet($subnet);
            }

            if (!$server->getKeyDirectory()) {
                $this->addFlash('danger', 'No base key directory is set on server "' . $server->getName() . '". Edit the server to set one.');
                return $this->redirectToRoute('ksk_rollover_start');
            }

            $zoneName = $domain ? $domain->getName() : $subnet->getReverseZoneName();
            $keyDir   = rtrim($server->getKeyDirectory(), '/') . '/' . $zoneName;

            $rollover->setDnsServer($server);
            $rollover->setAlgorithm($this->kskAlgorithm($rollover, $data['dnssecPolicy'] ?? null));
            $rollover->setKeyDirectory($keyDir);

            // Persist first so the record exists in DB before any SSH work.
            // This keeps the EntityManager clean: SSH exceptions are not DB exceptions
            // and cannot close the EM, so the subsequent flush always succeeds.
            $em->persist($rollover);
            $em->flush();

            try {
                $svc->startRollover($rollover);
                $this->addFlash('success', 'New KSK generated and loaded for "' . $rollover->getZoneName() . '".');
            } catch (\Throwable $e) {
                $rollover->setStatus(KskRolloverStatus::Failed);
                $rollover->addLog('Error: ' . $e->getMessage());
                $this->tryCleanup($svc, $rollover);
                $this->addFlash('danger', 'Rollover start failed: ' . $e->getMessage());
            }

            $em->flush();

            return $this->redirectToRoute('ksk_rollover_show', ['id' => $rollover->getId()]);
        }

        return $this->render('dnssec_ksk_rollover/start.html.twig', [
            'form'          => $form,
            'zonePolicyMap' => $zonePolicyMap,
        ]);
    }

    /** @return array{0: array, 1: array<string, int>} [zoneChoices, zonePolicyMap] */
    private function buildZoneChoices(EntityManagerInterface $em): array
    {
        $choices   = [];
        $policyMap = [];

        $domains = $em->getRepository(Domain::class)
            ->createQueryBuilder('d')
            ->where('d.dnssecPolicy IS NOT NULL')
            ->orderBy('d.name', 'ASC')
            ->getQuery()->getResult();

        foreach ($domains as $d) {
            $value                            = 'domain:' . $d->getId();
            $choices['Forward Zones'][$d->getName()] = $value;
            $policyMap[$value]                = $d->getDnssecPolicy()->getId();
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
            $value                             = 'subnet:' . $s->getId();
            $choices['Reverse Zones'][$label]  = $value;
            $policyMap[$value]                 = $s->getDnssecPolicy()->getId();
        }

        return [$choices, $policyMap];
    }

    #[Route('/{id}', name: 'ksk_rollover_show', methods: ['GET'])]
    public function show(DnssecKskRollover $rollover): Response
    {
        return $this->render('dnssec_ksk_rollover/show.html.twig', ['rollover' => $rollover]);
    }

    #[Route('/{id}/advance', name: 'ksk_rollover_advance', methods: ['POST'])]
    public function advance(Request $request, DnssecKskRollover $rollover, EntityManagerInterface $em, KskRolloverService $svc): Response
    {
        if (!$this->isCsrfTokenValid('ksk_rollover_advance_' . $rollover->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('ksk_rollover_show', ['id' => $rollover->getId()]);
        }

        $action = $request->request->get('action');

        try {
            match ($action) {
                'dnskey_propagated' => $this->transition($rollover, KskRolloverStatus::DsPending, 'DNSKEY propagation confirmed; please update DS at your registrar.', $em),
                'ds_submitted'      => $this->transition($rollover, KskRolloverStatus::DsSubmitted, 'DS record submitted to registrar; waiting for propagation.', $em),
                'retire_old_key'    => $this->retireOldKey($rollover, $svc),
                'complete'          => $this->transition($rollover, KskRolloverStatus::Complete, 'Rollover complete.', $em, completedAt: true),
                'fail'              => $this->failRollover($rollover, $svc, $em),
                default             => throw new \InvalidArgumentException("Unknown action: $action"),
            };
            // retire_old_key updates entity without flushing; flush here covers it.
            // transition() calls flush itself, so this is a harmless no-op for those.
            $em->flush();
        } catch (\Throwable $e) {
            $rollover->addLog('Error: ' . $e->getMessage());
            $em->flush();
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('ksk_rollover_show', ['id' => $rollover->getId()]);
    }

    // -------------------------------------------------------------------------

    /** Returns the KSK algorithm from the given policy (or the zone's policy), or a safe default. */
    private function kskAlgorithm(DnssecKskRollover $rollover, ?DnssecPolicy $override = null): string
    {
        $policy = $override ?? $rollover->getEffectiveDnssecPolicy();
        if ($policy) {
            foreach ($policy->getKeys() as $key) {
                if (($key['type'] ?? '') === 'ksk' && !empty($key['algorithm'])) {
                    return strtolower($key['algorithm']);
                }
            }
        }
        return 'ecdsap256sha256';
    }

    private function transition(DnssecKskRollover $rollover, KskRolloverStatus $status, string $logMsg, EntityManagerInterface $em, bool $completedAt = false): void
    {
        $rollover->setStatus($status);
        $rollover->addLog($logMsg);
        if ($completedAt) {
            $rollover->setCompletedAt(new \DateTimeImmutable());
        }
        $em->flush();
    }

    private function retireOldKey(DnssecKskRollover $rollover, KskRolloverService $svc): void
    {
        $svc->retireOldKey($rollover); // SSH only; entity updated but not flushed — caller flushes
        $this->addFlash('success', 'Old KSK retired; BIND will remove it after cache TTL expires.');
    }

    private function failRollover(DnssecKskRollover $rollover, KskRolloverService $svc, EntityManagerInterface $em): void
    {
        $this->tryCleanup($svc, $rollover);
        $rollover->setStatus(KskRolloverStatus::Failed);
        $rollover->addLog('Rollover marked as failed.');
        $rollover->setCompletedAt(new \DateTimeImmutable());
        $em->flush();
    }

    /** Attempts SSH key cleanup; logs any error but never throws. */
    private function tryCleanup(KskRolloverService $svc, DnssecKskRollover $rollover): void
    {
        try {
            $svc->cleanupNewKey($rollover);
        } catch (\Throwable $e) {
            $rollover->addLog('Cleanup error: ' . $e->getMessage());
        }
    }
}
