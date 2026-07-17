<?php

namespace App\Controller\Api;

use App\Entity\ApiToken;
use App\Entity\Domain;
use App\Entity\DomainRecord;
use App\Entity\Host;
use App\Entity\NetworkInterface;
use App\Enum\RecordType;
use App\Repository\DomainRecordRepository;
use App\Validator\TxtRecordValueValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/self')]
class SelfApiController extends AbstractController
{
    #[Route('/host', name: 'api_self_host', methods: ['GET'])]
    public function host(Request $request): JsonResponse
    {
        $token = $request->attributes->get('_api_token');
        if (!$token instanceof ApiToken || !$token->isHostScoped()) {
            return $this->json(['error' => 'This endpoint requires a host-scoped token.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json($this->serializeHost($token->getHost()));
    }

    #[Route('/dns-challenge', name: 'api_self_dns_challenge_create', methods: ['POST'])]
    public function createChallenge(
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
    ): JsonResponse {
        $token = $request->attributes->get('_api_token');
        if (!$token instanceof ApiToken || !$token->isHostScoped()) {
            return $this->json(['error' => 'This endpoint requires a host-scoped token.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $fqdn       = trim($data['fqdn'] ?? '');
        $validation = trim($data['validation'] ?? '');

        if ($fqdn === '' || $validation === '') {
            return $this->json(['error' => 'fqdn and validation are required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $match = $this->resolveOwnership($token->getHost(), $fqdn);
        if ($match === null) {
            return $this->json(['error' => 'The requested FQDN does not belong to this host.'], Response::HTTP_FORBIDDEN);
        }

        [$sourceRecord, $interface] = $match;

        if (!$this->domainHasPublicView($sourceRecord->getDomain())) {
            return $this->json(
                ['error' => 'The domain for this hostname has no public views — ACME validation from the internet is not possible.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $challengeHostname = $sourceRecord->getHostname() === '@'
            ? '_acme-challenge'
            : '_acme-challenge.' . $sourceRecord->getHostname();

        $record = new DomainRecord();
        $record->setHostname($challengeHostname);
        $record->setType(RecordType::TXT);
        $record->setValue(TxtRecordValueValidator::normalizeTxtValue($validation));
        $record->setDomain($sourceRecord->getDomain());
        $record->setNetworkInterface($interface);
        // Assign all domain views so the record appears in every zone file (including the
        // public/external one that Let's Encrypt queries), regardless of which views the
        // source A record itself is restricted to.
        foreach ($sourceRecord->getDomain()->getViews() as $view) {
            $record->addView($view);
        }

        $violations = $validator->validate($record);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $v) {
                $errors[] = $v->getMessage();
            }
            return $this->json(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em->persist($record);
        $em->flush();

        return $this->json(['id' => $record->getId(), 'hostname' => $challengeHostname], Response::HTTP_CREATED);
    }

    #[Route('/dns-challenge', name: 'api_self_dns_challenge_delete', methods: ['DELETE'])]
    public function deleteChallenge(
        Request $request,
        EntityManagerInterface $em,
        DomainRecordRepository $repo,
    ): JsonResponse {
        $token = $request->attributes->get('_api_token');
        if (!$token instanceof ApiToken || !$token->isHostScoped()) {
            return $this->json(['error' => 'This endpoint requires a host-scoped token.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $fqdn       = trim($data['fqdn'] ?? '');
        $validation = trim($data['validation'] ?? '');

        if ($fqdn === '' || $validation === '') {
            return $this->json(['error' => 'fqdn and validation are required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $match = $this->resolveOwnership($token->getHost(), $fqdn);
        if ($match === null) {
            return $this->json(['error' => 'The requested FQDN does not belong to this host.'], Response::HTTP_FORBIDDEN);
        }

        [$sourceRecord, $interface] = $match;

        $challengeHostname = $sourceRecord->getHostname() === '@'
            ? '_acme-challenge'
            : '_acme-challenge.' . $sourceRecord->getHostname();

        // Find challenge records by hostname, domain, interface, and value
        $normalizedValue = TxtRecordValueValidator::normalizeTxtValue($validation);
        $records = $repo->createQueryBuilder('r')
            ->where('r.hostname = :hostname')
            ->andWhere('r.domain = :domain')
            ->andWhere('r.networkInterface = :iface')
            ->andWhere('r.type = :type')
            ->andWhere('r.value = :value')
            ->setParameter('hostname', $challengeHostname)
            ->setParameter('domain', $sourceRecord->getDomain())
            ->setParameter('iface', $interface)
            ->setParameter('type', RecordType::TXT)
            ->setParameter('value', $normalizedValue)
            ->getQuery()
            ->getResult();

        if (empty($records)) {
            return $this->json(['error' => 'No matching challenge record found.'], Response::HTTP_NOT_FOUND);
        }

        foreach ($records as $record) {
            $em->remove($record);
        }
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Find a DomainRecord on the host's interfaces whose FQDN matches the given string.
     * Returns [DomainRecord, NetworkInterface] or null.
     *
     * @return array{0: DomainRecord, 1: NetworkInterface}|null
     */
    private function resolveOwnership(Host $host, string $fqdn): ?array
    {
        foreach ($host->getInterfaces() as $iface) {
            if ($iface->isDeleted()) {
                continue;
            }
            foreach ($iface->getDomainRecords() as $record) {
                if ($record->getFullyQualifiedHostname() === $fqdn) {
                    return [$record, $iface];
                }
            }
        }
        return null;
    }

    /**
     * A domain is publicly reachable if it has no view restrictions, or if at least
     * one of its views is marked as public. Used to filter the hostname list so ACME
     * clients only see names they can realistically certify from the internet.
     */
    private function domainHasPublicView(Domain $domain): bool
    {
        if ($domain->getViews()->isEmpty()) {
            return true; // no view restriction → globally accessible
        }
        foreach ($domain->getViews() as $view) {
            if ($view->isPublic()) {
                return true;
            }
        }
        return false;
    }

    private function serializeHost(Host $host): array
    {
        $interfaces = [];
        foreach ($host->getInterfaces() as $iface) {
            if ($iface->isDeleted()) {
                continue;
            }
            $records = [];
            foreach ($iface->getDomainRecords() as $record) {
                $domain = $record->getDomain();
                if ($domain === null || !$this->domainHasPublicView($domain)) {
                    continue;
                }
                $records[] = [
                    'id'        => $record->getId(),
                    'hostname'  => $record->getHostname(),
                    'fqdn'      => $record->getFullyQualifiedHostname(),
                    'type'      => $record->getType()->value,
                    'value'     => $record->getValue(),
                    'domain_id' => $domain->getId(),
                ];
            }
            $interfaces[] = [
                'id'      => $iface->getId(),
                'name'    => $iface->getName(),
                'mac'     => $iface->getMacAddress(),
                'ipv4'    => $iface->getIpAddress()?->getAddress(),
                'ipv6'    => $iface->getIpv6Address()?->getAddress(),
                'records' => $records,
            ];
        }

        return [
            'id'         => $host->getId(),
            'name'       => $host->getName(),
            'interfaces' => $interfaces,
        ];
    }
}
