<?php

namespace App\Controller;

use App\Entity\DnsView;
use App\Entity\DomainRecord;
use App\Entity\NetworkInterface;
use App\Entity\VirtualIp;
use App\Enum\RecordType;
use App\Service\DnsViewResolver;
use App\Repository\DhcpLeaseRepository;
use App\Service\RecommendationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ReportController extends AbstractController
{
    #[Route('/reports/subnet-change', name: 'report_subnet_change', methods: ['GET'])]
    public function subnetChange(
        Request $request,
        DhcpLeaseRepository $leaseRepo,
    ): Response {
        $cutoverStr = trim((string) $request->query->get('cutover', ''));

        $rows = null;
        if ($cutoverStr !== '') {
            try {
                $cutover = new \DateTimeImmutable($cutoverStr);
                $rows = $leaseRepo->findSubnetChanges($cutover);
            } catch (\Exception) {
                $rows = null;
            }
        }

        return $this->render('report/subnet_change.html.twig', [
            'cutover' => $cutoverStr,
            'rows'    => $rows,
        ]);
    }

    #[Route('/recommendations', name: 'recommendation_index', methods: ['GET'])]
    public function recommendations(RecommendationService $service): Response
    {
        return $this->render('report/recommendations.html.twig', [
            'unlinked_dns'        => $service->findUnlinkedDnsRecords(),
            'convertible_cnames'  => $service->findConvertibleCnameRecords(),
            'missing_dual_stack'  => $service->findMissingDualStackRecords(),
            'missing_views'       => $service->findRecordsWithMissingViews(),
        ]);
    }

    #[Route('/recommendations/apply/link-dns', name: 'recommendation_apply_link_dns', methods: ['POST'])]
    public function applyLinkDns(
        Request $request,
        EntityManagerInterface $em,
        RecommendationService $service,
    ): Response {
        if (!$this->isCsrfTokenValid('link_dns_bulk', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('recommendation_index');
        }

        $ids = array_filter(array_map('intval', (array) $request->request->all('ids')));
        if (empty($ids)) {
            $this->addFlash('warning', 'No records selected.');
            return $this->redirectToRoute('recommendation_index');
        }

        $linked = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            $record = $em->find(DomainRecord::class, $id);
            if ($record === null || $record->getNetworkInterface() !== null) {
                $skipped++;
                continue;
            }

            if (!in_array($record->getType(), [RecordType::A, RecordType::AAAA], true)) {
                $skipped++;
                continue;
            }

            $iface = $service->findInterfaceForDnsRecord($record);
            if ($iface !== null) {
                $record->setNetworkInterface($iface);
                $record->setValue('');
            } else {
                $vip = $service->findVipForDnsRecord($record);
                if ($vip === null) {
                    $skipped++;
                    continue;
                }
                $record->setVirtualIp($vip);
                $record->setValue('');
            }
            $linked++;
        }

        $em->flush();

        if ($linked > 0) {
            $this->addFlash('success', sprintf('%d DNS record%s linked to interface%s.', $linked, $linked !== 1 ? 's' : '', $linked !== 1 ? 's' : ''));
        }
        if ($skipped > 0) {
            $this->addFlash('warning', sprintf('%d record%s skipped (already linked or no longer matched).', $skipped, $skipped !== 1 ? 's' : ''));
        }

        return $this->redirectToRoute('recommendation_index');
    }

    #[Route('/recommendations/apply/convert-cname', name: 'recommendation_apply_convert_cname', methods: ['POST'])]
    public function applyConvertCname(
        Request $request,
        EntityManagerInterface $em,
        RecommendationService $service,
    ): Response {
        if (!$this->isCsrfTokenValid('convert_cname_bulk', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('recommendation_index');
        }

        $ids = array_filter(array_map('intval', (array) $request->request->all('ids')));
        if (empty($ids)) {
            $this->addFlash('warning', 'No records selected.');
            return $this->redirectToRoute('recommendation_index');
        }

        $converted = 0;
        $skipped   = 0;

        foreach ($ids as $id) {
            $record = $em->find(DomainRecord::class, $id);
            if ($record === null || $record->getType() !== RecordType::CNAME
                || $record->getNetworkInterface() !== null || $record->getVirtualIp() !== null) {
                $skipped++;
                continue;
            }

            $targets = $service->findCnameConversionTargets($id);
            if (empty($targets)) {
                $skipped++;
                continue;
            }

            $views    = $record->getViews()->toArray();
            $domain   = $record->getDomain();
            $hostname = $record->getHostname();
            $ttl      = $record->getTtl();
            $comment  = $record->getComment();

            // Convert the existing record in-place for the first target
            $first = array_shift($targets);
            $record->setType(RecordType::from($first['target_type']));
            $record->setValue('');
            if ($first['match_type'] === 'interface') {
                $iface = $em->find(NetworkInterface::class, $first['match_id']);
                if ($iface === null || $iface->isDeleted()) { $skipped++; continue; }
                $record->setNetworkInterface($iface);
            } else {
                $vip = $em->find(VirtualIp::class, $first['match_id']);
                if ($vip === null || $vip->isDeleted()) { $skipped++; continue; }
                $record->setVirtualIp($vip);
            }

            // Create additional records for any remaining targets
            foreach ($targets as $t) {
                $extra = new DomainRecord();
                $extra->setDomain($domain);
                $extra->setHostname($hostname);
                $extra->setType(RecordType::from($t['target_type']));
                $extra->setValue('');
                $extra->setTtl($ttl);
                $extra->setComment($comment);
                foreach ($views as $view) {
                    $extra->addView($view);
                }
                if ($t['match_type'] === 'interface') {
                    $iface = $em->find(NetworkInterface::class, $t['match_id']);
                    if ($iface === null || $iface->isDeleted()) continue;
                    $extra->setNetworkInterface($iface);
                } else {
                    $vip = $em->find(VirtualIp::class, $t['match_id']);
                    if ($vip === null || $vip->isDeleted()) continue;
                    $extra->setVirtualIp($vip);
                }
                $em->persist($extra);
            }

            $converted++;
        }

        $em->flush();

        if ($converted > 0) {
            $this->addFlash('success', sprintf('%d CNAME record%s converted.', $converted, $converted !== 1 ? 's' : ''));
        }
        if ($skipped > 0) {
            $this->addFlash('warning', sprintf('%d record%s skipped (already changed or no longer matched).', $skipped, $skipped !== 1 ? 's' : ''));
        }

        return $this->redirectToRoute('recommendation_index');
    }

    #[Route('/recommendations/apply/add-dual-stack', name: 'recommendation_apply_add_dual_stack', methods: ['POST'])]
    public function applyAddDualStack(
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('add_dual_stack_bulk', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('recommendation_index');
        }

        $targets = (array) $request->request->all('targets');
        if (empty($targets)) {
            $this->addFlash('warning', 'No records selected.');
            return $this->redirectToRoute('recommendation_index');
        }

        $added   = 0;
        $skipped = 0;

        foreach ($targets as $target) {
            $parts = explode(':', (string) $target, 2);
            if (count($parts) !== 2) { $skipped++; continue; }

            $existing = $em->find(DomainRecord::class, (int) $parts[0]);
            $type     = RecordType::tryFrom($parts[1]);

            if ($existing === null || $type === null || !in_array($type, [RecordType::A, RecordType::AAAA], true)) {
                $skipped++;
                continue;
            }

            $iface = $existing->getNetworkInterface();
            $vip   = $existing->getVirtualIp();
            if ($iface === null && $vip === null) { $skipped++; continue; }

            // Guard: skip if the record was already created since page load
            $qb = $em->createQueryBuilder()
                ->select('COUNT(dr.id)')->from(DomainRecord::class, 'dr')
                ->where('dr.hostname = :h')->andWhere('dr.domain = :d')->andWhere('dr.type = :t')
                ->setParameter('h', $existing->getHostname())
                ->setParameter('d', $existing->getDomain())
                ->setParameter('t', $type);
            if ($iface !== null) {
                $qb->andWhere('dr.networkInterface = :e')->setParameter('e', $iface);
            } else {
                $qb->andWhere('dr.virtualIp = :e')->setParameter('e', $vip);
            }
            if ((int) $qb->getQuery()->getSingleScalarResult() > 0) { $skipped++; continue; }

            $new = new DomainRecord();
            $new->setDomain($existing->getDomain());
            $new->setHostname($existing->getHostname());
            $new->setType($type);
            $new->setValue('');
            $new->setTtl($existing->getTtl());
            $new->setComment($existing->getComment());
            foreach ($existing->getViews() as $view) {
                $new->addView($view);
            }
            $iface !== null ? $new->setNetworkInterface($iface) : $new->setVirtualIp($vip);
            $em->persist($new);
            $added++;
        }

        $em->flush();

        if ($added > 0) {
            $this->addFlash('success', sprintf('%d missing DNS record%s added.', $added, $added !== 1 ? 's' : ''));
        }
        if ($skipped > 0) {
            $this->addFlash('warning', sprintf('%d item%s skipped (already exists or data changed).', $skipped, $skipped !== 1 ? 's' : ''));
        }

        return $this->redirectToRoute('recommendation_index');
    }

    #[Route('/recommendations/apply/add-missing-views', name: 'recommendation_apply_add_missing_views', methods: ['POST'])]
    public function applyAddMissingViews(
        Request $request,
        EntityManagerInterface $em,
        DnsViewResolver $viewResolver,
    ): Response {
        if (!$this->isCsrfTokenValid('add_missing_views_bulk', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('recommendation_index');
        }

        $ids = array_filter(array_map('intval', (array) $request->request->all('ids')));
        if (empty($ids)) {
            $this->addFlash('warning', 'No records selected.');
            return $this->redirectToRoute('recommendation_index');
        }

        $updated = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            $record = $em->find(DomainRecord::class, $id);
            if ($record === null || !$record->getViews()->isEmpty()) {
                $skipped++;
                continue;
            }

            $subnet = $record->getNetworkInterface()?->getSubnet()
                   ?? $record->getVirtualIp()?->getSubnet();

            $views = $viewResolver->availableViewsFor($record->getDomain(), $subnet);
            if (empty($views)) {
                $skipped++;
                continue;
            }

            foreach ($views as $view) {
                $record->addView($view);
            }
            $updated++;
        }

        $em->flush();

        if ($updated > 0) {
            $this->addFlash('success', sprintf('%d DNS record%s updated with missing views.', $updated, $updated !== 1 ? 's' : ''));
        }
        if ($skipped > 0) {
            $this->addFlash('warning', sprintf('%d record%s skipped (already has views or no applicable views).', $skipped, $skipped !== 1 ? 's' : ''));
        }

        return $this->redirectToRoute('recommendation_index');
    }
}
