<?php

namespace App\Controller;

use App\Entity\DomainRecord;
use App\Enum\RecordType;
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
            'unlinked_dns' => $service->findUnlinkedDnsRecords(),
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
}
