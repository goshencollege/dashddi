<?php

namespace App\Controller;

use App\Entity\DnssecKskRollover;
use App\Enum\KskRolloverStatus;
use App\Form\KskRolloverStartType;
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
    public function start(Request $request, EntityManagerInterface $em, KskRolloverService $svc, DnssecKskRolloverRepository $repo, DnsServerRepository $serverRepo): Response
    {
        $firstServer = $serverRepo->findOneBy([], ['name' => 'ASC']);

        $form = $this->createForm(KskRolloverStartType::class, null, ['first_server' => $firstServer]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data   = $form->getData();
            $domain = $data['domain'];
            $server = $data['dnsServer'];

            if ($repo->findActiveForDomain($domain)) {
                $this->addFlash('danger', 'An active KSK rollover already exists for "' . $domain->getName() . '".');
                return $this->redirectToRoute('ksk_rollover_index');
            }

            $keyDir = $domain->getKeyDirectory();
            if (!$keyDir) {
                $this->addFlash('danger', 'No key directory is set on domain "' . $domain->getName() . '". Edit the domain to set one.');
                return $this->redirectToRoute('ksk_rollover_start');
            }

            $algorithm = $this->kskAlgorithm($domain);

            $rollover = new DnssecKskRollover();
            $rollover->setDomain($domain);
            $rollover->setDnsServer($server);
            $rollover->setAlgorithm($algorithm);
            $rollover->setKeyDirectory($keyDir);

            $em->persist($rollover);

            try {
                $svc->startRollover($rollover);
                $this->addFlash('success', 'New KSK generated and loaded for "' . $domain->getName() . '".');
            } catch (\Throwable $e) {
                $rollover->setStatus(KskRolloverStatus::Failed);
                $rollover->addLog('Error: ' . $e->getMessage());
                $em->flush();
                $this->addFlash('danger', 'Rollover start failed: ' . $e->getMessage());
            }

            return $this->redirectToRoute('ksk_rollover_show', ['id' => $rollover->getId()]);
        }

        return $this->render('dnssec_ksk_rollover/start.html.twig', ['form' => $form]);
    }

    #[Route('/{id}', name: 'ksk_rollover_show', methods: ['GET'])]
    public function show(DnssecKskRollover $rollover): Response
    {
        return $this->render('dnssec_ksk_rollover/show.html.twig', ['rollover' => $rollover]);
    }

    #[Route('/{id}/advance', name: 'ksk_rollover_advance', methods: ['POST'])]
    public function advance(Request $request, DnssecKskRollover $rollover, EntityManagerInterface $em, KskRolloverService $svc): Response
    {
        $action = $request->request->get('action');

        try {
            match ($action) {
                'dnskey_propagated' => $this->transition($rollover, KskRolloverStatus::DsPending, 'DNSKEY propagation confirmed; please update DS at your registrar.', $em),
                'ds_submitted'      => $this->transition($rollover, KskRolloverStatus::DsSubmitted, 'DS record submitted to registrar; waiting for propagation.', $em),
                'retire_old_key'    => $this->retireOldKey($rollover, $svc, $em),
                'complete'          => $this->transition($rollover, KskRolloverStatus::Complete, 'Rollover complete.', $em, completedAt: true),
                'fail'              => $this->transition($rollover, KskRolloverStatus::Failed, 'Rollover marked as failed.', $em, completedAt: true),
                default             => throw new \InvalidArgumentException("Unknown action: $action"),
            };
        } catch (\Throwable $e) {
            $rollover->addLog('Error: ' . $e->getMessage());
            $em->flush();
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('ksk_rollover_show', ['id' => $rollover->getId()]);
    }

    // -------------------------------------------------------------------------

    /** Returns the KSK algorithm from the domain's DNSSEC policy, or a safe default. */
    private function kskAlgorithm(\App\Entity\Domain $domain): string
    {
        $policy = $domain->getDnssecPolicy();
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

    private function retireOldKey(DnssecKskRollover $rollover, KskRolloverService $svc, EntityManagerInterface $em): void
    {
        $svc->retireOldKey($rollover);
        $this->addFlash('success', 'Old KSK retired; BIND will remove it after cache TTL expires.');
    }
}
