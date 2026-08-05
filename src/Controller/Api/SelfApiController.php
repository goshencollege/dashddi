<?php

namespace App\Controller\Api;

use App\Entity\ApiToken;
use App\Entity\Domain;
use App\Entity\DomainRecord;
use App\Entity\Host;
use App\Entity\NetworkInterface;
use App\Enum\RecordType;
use App\Repository\DomainRecordRepository;
use App\Repository\DomainRepository;
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
    public function __construct(
        private readonly DomainRepository $domainRepository,
    ) {}

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

        $resolved = $this->resolveChallengeDomain($sourceRecord);
        if ($resolved === null) {
            return $this->json(
                ['error' => 'The domain for this hostname has no public views — ACME validation from the internet is not possible.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        [$targetDomain, $challengeHostname] = $resolved;

        $record = new DomainRecord();
        $record->setHostname($challengeHostname);
        $record->setType(RecordType::TXT);
        $record->setValue(TxtRecordValueValidator::normalizeTxtValue($validation));
        $record->setDomain($targetDomain);
        $record->setNetworkInterface($interface);
        // Assign all target domain views so the record appears in every zone file (including
        // the public/external one that Let's Encrypt queries).
        foreach ($targetDomain->getViews() as $view) {
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

        return $this->json(['id' => $record->getId(), 'hostname' => $challengeHostname, 'domain' => $targetDomain->getName()], Response::HTTP_CREATED);
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

        // Compute the challenge hostname prefix from the source record so we only match
        // _acme-challenge records for this specific hostname — not other TXT records on
        // the same interface. We match both the direct form (_acme-challenge.srv) and the
        // parent-domain form (_acme-challenge.srv.internal.*) without re-deriving which
        // domain was chosen at creation time.
        $hostnameBase = $sourceRecord->getHostname() === '@'
            ? '_acme-challenge'
            : '_acme-challenge.' . $sourceRecord->getHostname();

        $normalizedValue = TxtRecordValueValidator::normalizeTxtValue($validation);
        $records = $repo->createQueryBuilder('r')
            ->where('r.networkInterface = :iface')
            ->andWhere('r.type = :type')
            ->andWhere('r.value = :value')
            ->andWhere('r.hostname = :exact OR r.hostname LIKE :prefix')
            ->setParameter('iface', $interface)
            ->setParameter('type', RecordType::TXT)
            ->setParameter('value', $normalizedValue)
            ->setParameter('exact', $hostnameBase)
            ->setParameter('prefix', $hostnameBase . '.%')
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

    /**
     * Resolve the domain and hostname for an ACME challenge record.
     * Returns [$targetDomain, $challengeHostname] or null if no public zone is reachable.
     *
     * @return array{0: Domain, 1: string}|null
     */
    private function resolveChallengeDomain(DomainRecord $sourceRecord): ?array
    {
        $sourceDomain = $sourceRecord->getDomain();

        if ($this->domainHasExplicitPublicView($sourceDomain)) {
            $targetDomain = $sourceDomain;
        } else {
            // Walk up to a public parent. This handles both private subdomains (has
            // views but none public) and view-less domains whose DNS is managed
            // externally (e.g. Active Directory), where the challenge must be
            // published via a DashDDI-managed public ancestor instead.
            $targetDomain = $this->findPublicParentDomain($sourceDomain->getName());

            // If no public parent exists, fall back to the source domain only when
            // it has no view restrictions — meaning DashDDI serves it globally.
            if ($targetDomain === null && $sourceDomain->getViews()->isEmpty()) {
                $targetDomain = $sourceDomain;
            }
        }

        if ($targetDomain === null) {
            return null;
        }
        return [$targetDomain, $this->challengeHostnameInParentDomain($sourceRecord, $targetDomain)];
    }

    /**
     * Returns true only when the domain has at least one view explicitly marked
     * as public. Unlike domainHasPublicView(), view-less domains return false —
     * a domain with no views may not be actively managed by DashDDI for DNS, so
     * placing a challenge record there may not publish it to the internet.
     */
    private function domainHasExplicitPublicView(Domain $domain): bool
    {
        foreach ($domain->getViews() as $view) {
            if ($view->isPublic()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Walk up the DNS label hierarchy to find the nearest ancestor domain that
     * exists in DashDDI and has at least one public view. Used as a fallback when
     * the source record's own domain is internal-only, so the ACME challenge TXT
     * can be published in the public parent zone instead.
     */
    private function findPublicParentDomain(string $domainName): ?Domain
    {
        $parts = explode('.', $domainName);
        // Start at index 1 (skip the source domain itself) and stop before bare TLDs
        for ($i = 1; $i < count($parts) - 1; $i++) {
            $candidate = implode('.', array_slice($parts, $i));
            $domain = $this->domainRepository->findOneBy(['name' => $candidate]);
            if ($domain !== null && $this->domainHasPublicView($domain)) {
                return $domain;
            }
        }
        return null;
    }

    /**
     * Compute the hostname for a challenge TXT record placed in a parent zone.
     * Example: source = "host" in "internal.example.com", parent = "example.com"
     * → challenge FQDN = "_acme-challenge.host.internal.example.com"
     * → relative hostname = "_acme-challenge.host.internal"
     */
    private function challengeHostnameInParentDomain(DomainRecord $sourceRecord, Domain $parentDomain): string
    {
        $hostname = $sourceRecord->getHostname();
        $sourceDomainName = $sourceRecord->getDomain()->getName();
        $sourceFqdn = $hostname === '@' ? $sourceDomainName : $hostname . '.' . $sourceDomainName;
        $challengeFqdn = '_acme-challenge.' . $sourceFqdn;
        return substr($challengeFqdn, 0, -(strlen($parentDomain->getName()) + 1));
    }

    private function serializeHost(Host $host): array
    {
        $reachableCache = []; // domain name → bool, memoizes domainHasPublicView + parent lookup
        $interfaces = [];
        foreach ($host->getInterfaces() as $iface) {
            if ($iface->isDeleted()) {
                continue;
            }
            $records = [];
            foreach ($iface->getDomainRecords() as $record) {
                $domain = $record->getDomain();
                if ($domain === null) {
                    continue;
                }
                $domainName = $domain->getName();
                if (!isset($reachableCache[$domainName])) {
                    $reachableCache[$domainName] = $this->domainHasPublicView($domain)
                        || $this->findPublicParentDomain($domainName) !== null;
                }
                if (!$reachableCache[$domainName]) {
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
