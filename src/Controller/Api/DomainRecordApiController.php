<?php

namespace App\Controller\Api;

use App\Entity\DomainRecord;
use App\Enum\RecordType;
use App\Repository\DnsViewRepository;
use App\Repository\DomainRecordRepository;
use App\Repository\DomainRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/domain-records')]
class DomainRecordApiController extends AbstractController
{
    #[Route('', name: 'api_domain_records_index', methods: ['GET'])]
    public function index(Request $request, DomainRecordRepository $repo): JsonResponse
    {
        $qb = $repo->createQueryBuilder('r');

        if ($domainId = $request->query->getInt('domain_id')) {
            $qb->andWhere('r.domain = :did')->setParameter('did', $domainId);
        }
        if ($type = $request->query->get('type')) {
            $qb->andWhere('r.type = :type')->setParameter('type', $type);
        }

        $records = $qb->orderBy('r.hostname', 'ASC')->getQuery()->getResult();

        return $this->json(array_map($this->serialize(...), $records));
    }

    #[Route('/{id}', name: 'api_domain_records_show', methods: ['GET'])]
    public function show(DomainRecord $domainRecord): JsonResponse
    {
        return $this->json($this->serialize($domainRecord));
    }

    #[Route('', name: 'api_domain_records_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        DomainRepository $domainRepo,
        DnsViewRepository $viewRepo,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        if (empty($data['domain_id'])) {
            return $this->json(['error' => 'domain_id is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (empty($data['hostname'])) {
            return $this->json(['error' => 'hostname is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (empty($data['value'])) {
            return $this->json(['error' => 'value is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $domain = $domainRepo->find($data['domain_id']);
        if (!$domain) {
            return $this->json(['error' => 'domain_id not found'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $type = RecordType::tryFrom(strtoupper($data['type'] ?? ''));
        if (!$type) {
            return $this->json(
                ['error' => 'type must be one of: ' . implode(', ', array_column(RecordType::cases(), 'value'))],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $record = new DomainRecord();
        $record->setDomain($domain);
        $record->setHostname($data['hostname']);
        $record->setType($type);
        $record->setValue($data['value']);
        $record->setTtl(isset($data['ttl']) ? (int) $data['ttl'] : null);

        foreach ($data['view_ids'] ?? [] as $viewId) {
            $view = $viewRepo->find($viewId);
            if ($view) {
                $record->addView($view);
            }
        }

        $em->persist($record);
        $em->flush();

        return $this->json($this->serialize($record), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_domain_records_update', methods: ['PATCH'])]
    public function update(
        Request $request,
        DomainRecord $domainRecord,
        EntityManagerInterface $em,
        DomainRepository $domainRepo,
        DnsViewRepository $viewRepo,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('domain_id', $data)) {
            $domain = $domainRepo->find($data['domain_id']);
            if (!$domain) {
                return $this->json(['error' => 'domain_id not found'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $domainRecord->setDomain($domain);
        }

        if (array_key_exists('hostname', $data)) {
            if (empty($data['hostname'])) {
                return $this->json(['error' => 'hostname cannot be empty'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $domainRecord->setHostname($data['hostname']);
        }

        if (array_key_exists('type', $data)) {
            $type = RecordType::tryFrom(strtoupper($data['type'] ?? ''));
            if (!$type) {
                return $this->json(
                    ['error' => 'type must be one of: ' . implode(', ', array_column(RecordType::cases(), 'value'))],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
            $domainRecord->setType($type);
        }

        if (array_key_exists('value', $data)) {
            if (empty($data['value'])) {
                return $this->json(['error' => 'value cannot be empty'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $domainRecord->setValue($data['value']);
        }

        if (array_key_exists('ttl', $data)) {
            $domainRecord->setTtl($data['ttl'] !== null ? (int) $data['ttl'] : null);
        }

        if (array_key_exists('view_ids', $data)) {
            foreach ($domainRecord->getViews()->toArray() as $view) {
                $domainRecord->removeView($view);
            }
            foreach ($data['view_ids'] as $viewId) {
                $view = $viewRepo->find($viewId);
                if ($view) {
                    $domainRecord->addView($view);
                }
            }
        }

        $em->flush();

        return $this->json($this->serialize($domainRecord));
    }

    #[Route('/{id}', name: 'api_domain_records_delete', methods: ['DELETE'])]
    public function delete(DomainRecord $domainRecord, EntityManagerInterface $em): JsonResponse
    {
        $em->remove($domainRecord);
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function serialize(DomainRecord $record): array
    {
        return [
            'id'         => $record->getId(),
            'domain_id'  => $record->getDomain()?->getId(),
            'hostname'   => $record->getHostname(),
            'type'       => $record->getType()->value,
            'value'      => $record->getValue(),
            'ttl'        => $record->getTtl(),
            'view_ids'   => $record->getViews()->map(fn($v) => $v->getId())->toArray(),
            'created_at' => $record->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $record->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'created_by' => $record->getCreatedBy(),
            'updated_by' => $record->getUpdatedBy(),
        ];
    }
}
