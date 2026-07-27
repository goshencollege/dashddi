<?php

namespace App\Controller;

use App\Repository\ActivityLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/activity-log')]
class ActivityLogController extends AbstractController
{
    private const PER_PAGE = 100;

    #[Route('', name: 'activity_log_index', methods: ['GET'])]
    public function index(Request $request, ActivityLogRepository $repo): Response
    {
        $filters = [
            'userIdentifier' => $request->query->get('user') ?: null,
            'entityType'     => $request->query->get('entity_type') ?: null,
            'entityLabel'    => $request->query->get('entity_name') ?: null,
            'action'         => $request->query->get('action') ?: null,
            'dateFrom'       => $request->query->get('date_from') ?: null,
            'dateTo'         => $request->query->get('date_to') ?: null,
        ];

        $page  = max(1, (int) $request->query->get('page', 1));
        $limit = self::PER_PAGE;
        $offset = ($page - 1) * $limit;

        $logs  = $repo->findFiltered($filters, $limit, $offset);
        $total = $repo->countFiltered($filters);
        $pages = (int) ceil($total / $limit);

        return $this->render('activity_log/index.html.twig', [
            'logs'    => $logs,
            'filters' => $filters,
            'page'    => $page,
            'pages'   => $pages,
            'total'   => $total,
        ]);
    }
}
