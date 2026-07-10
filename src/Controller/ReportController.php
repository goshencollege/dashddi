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

    #[Route('/recommendations/apply/link-dns/{id}', name: 'recommendation_apply_link_dns', methods: ['POST'])]
    public function applyLinkDns(
        int $id,
        Request $request,
        EntityManagerInterface $em,
        RecommendationService $service,
    ): Response {
        if (!$this->isCsrfTokenValid('link_dns_' . $id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('recommendation_index');
        }

        $record = $em->find(DomainRecord::class, $id);
        if ($record === null) {
            $this->addFlash('warning', 'Record not found.');
            return $this->redirectToRoute('recommendation_index');
        }

        if ($record->getNetworkInterface() !== null) {
            $this->addFlash('info', 'Record is already linked to an interface.');
            return $this->redirectToRoute('recommendation_index');
        }

        if (!in_array($record->getType(), [RecordType::A, RecordType::AAAA], true)) {
            $this->addFlash('warning', 'Only A and AAAA records can be linked via this action.');
            return $this->redirectToRoute('recommendation_index');
        }

        $iface = $service->findInterfaceForDnsRecord($record);
        if ($iface === null) {
            $this->addFlash('warning', 'No matching interface found — the data may have changed.');
            return $this->redirectToRoute('recommendation_index');
        }

        $record->setNetworkInterface($iface);
        $record->setValue('');
        $em->flush();

        $this->addFlash('success', sprintf(
            'DNS record "%s" linked to interface on host "%s".',
            $record->getHostname(),
            $iface->getHost()?->getName() ?? '(unknown)',
        ));

        return $this->redirectToRoute('recommendation_index');
    }
}
