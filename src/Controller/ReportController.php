<?php

namespace App\Controller;

use App\Repository\DhcpLeaseRepository;
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
}
