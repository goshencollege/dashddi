<?php

namespace App\Controller\Api;

use App\Entity\DomainRecord;
use App\Enum\RecordType;
use App\Repository\DnsViewRepository;
use App\Repository\DomainRecordRepository;
use App\Repository\DomainRepository;
use App\Repository\NetworkInterfaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Validator\TxtRecordValueValidator;
use Symfony\Component\Validator\Validator\ValidatorInterface;

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
        if ($interfaceId = $request->query->getInt('interface_id')) {
            $qb->andWhere('r.networkInterface = :iid')->setParameter('iid', $interfaceId);
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
        NetworkInterfaceRepository $interfaceRepo,
        DnsViewRepository $viewRepo,
        ValidatorInterface $validator,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        if (empty($data['hostname'])) {
            return $this->json(['error' => 'hostname is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $type = RecordType::tryFrom(strtoupper($data['type'] ?? ''));
        if (!$type) {
            return $this->json(
                ['error' => 'type must be one of: ' . implode(', ', array_column(RecordType::cases(), 'value'))],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $record = new DomainRecord();
        $record->setHostname($data['hostname']);
        $record->setType($type);
        $record->setTtl(isset($data['ttl']) ? (int) $data['ttl'] : null);

        // Interface-linked record
        if (!empty($data['interface_id'])) {
            $interface = $interfaceRepo->find($data['interface_id']);
            if (!$interface) {
                return $this->json(['error' => 'interface_id not found'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $record->setNetworkInterface($interface);
            $record->setIsCanonical((bool) ($data['is_canonical'] ?? false));
        }

        // Domain association
        if (!empty($data['domain_id'])) {
            $domain = $domainRepo->find($data['domain_id']);
            if (!$domain) {
                return $this->json(['error' => 'domain_id not found'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $record->setDomain($domain);
        } elseif ($record->getNetworkInterface() === null) {
            return $this->json(['error' => 'domain_id is required for records not linked to an interface'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Value required for non-interface records, or for record types other than A/AAAA
        $isInterfaceAorAAAA = $record->getNetworkInterface() !== null
            && in_array($type, [RecordType::A, RecordType::AAAA], true);

        if (!$isInterfaceAorAAAA) {
            if (empty($data['value'])) {
                return $this->json(['error' => 'value is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $value = $data['value'];
            if ($type === RecordType::TXT) {
                $value = TxtRecordValueValidator::normalizeTxtValue($value);
            }
            $record->setValue($value);
        }

        foreach ($data['view_ids'] ?? [] as $viewId) {
            $view = $viewRepo->find($viewId);
            if ($view) {
                $record->addView($view);
            }
        }

        $violations = $validator->validate($record);
        if (count($violations) > 0) {
            return $this->json(['errors' => $this->formatViolations($violations)], Response::HTTP_UNPROCESSABLE_ENTITY);
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
        ValidatorInterface $validator,
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
            $value = $data['value'] ?? '';
            if ($domainRecord->getType() === RecordType::TXT) {
                $value = TxtRecordValueValidator::normalizeTxtValue($value);
            }
            $domainRecord->setValue($value);
        }

        if (array_key_exists('ttl', $data)) {
            $domainRecord->setTtl($data['ttl'] !== null ? (int) $data['ttl'] : null);
        }

        if (array_key_exists('is_canonical', $data)) {
            $domainRecord->setIsCanonical((bool) $data['is_canonical']);
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

        $violations = $validator->validate($domainRecord);
        if (count($violations) > 0) {
            return $this->json(['errors' => $this->formatViolations($violations)], Response::HTTP_UNPROCESSABLE_ENTITY);
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

    private function formatViolations(\Symfony\Component\Validator\ConstraintViolationListInterface $violations): array
    {
        $errors = [];
        foreach ($violations as $violation) {
            $field = ltrim($violation->getPropertyPath(), '.');
            $errors[$field ?: 'record'][] = $violation->getMessage();
        }
        return $errors;
    }

    private function serialize(DomainRecord $record): array
    {
        return [
            'id'           => $record->getId(),
            'domain_id'    => $record->getDomain()?->getId(),
            'interface_id' => $record->getNetworkInterface()?->getId(),
            'hostname'     => $record->getHostname(),
            'fqdn'         => $record->getDomain() ? $record->getFullyQualifiedHostname() . '.' . $record->getDomain()->getName() : $record->getHostname(),
            'type'         => $record->getType()->value,
            'value'        => $record->getValue(),
            'ttl'          => $record->getTtl(),
            'is_canonical' => $record->isCanonical(),
            'view_ids'     => $record->getViews()->map(fn($v) => $v->getId())->toArray(),
            'created_at'   => $record->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at'   => $record->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'created_by'   => $record->getCreatedBy(),
            'updated_by'   => $record->getUpdatedBy(),
        ];
    }
}
