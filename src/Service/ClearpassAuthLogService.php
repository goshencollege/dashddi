<?php

namespace App\Service;

use App\Entity\ClearpassAuthLog;
use App\Entity\ClearpassServer;
use App\Repository\ClearpassAuthLogRepository;
use App\Repository\NetworkInterfaceRepository;
use Doctrine\ORM\EntityManagerInterface;

class ClearpassAuthLogService
{
    private const PAGE_SIZE = 1000;

    public function __construct(
        private readonly ClearpassAuthLogRepository $logRepo,
        private readonly NetworkInterfaceRepository $ifaceRepo,
        private readonly EntityManagerInterface     $em,
    ) {}

    /**
     * Probes candidate API paths and returns a map of path => first record (or error string).
     * Used by the --debug command to discover which Insight endpoint is available.
     * If $mac is provided, filters results to sessions matching that MAC address.
     *
     * @return array<string, array|string>
     */
    public function probeEndpoints(ClearpassServer $server, string $mac = ''): array
    {
        $token = $this->getAccessToken($server);

        $candidates = [
            '/api/v1/session',
        ];

        $results = [];
        foreach ($candidates as $path) {
            $params = ['limit' => 1];
            if ($mac !== '') {
                $normalised = $this->normaliseMac($mac);
                $filterMac  = $normalised !== '' ? $normalised : $mac;
                $params['filter'] = json_encode(['mac_address' => $filterMac]);
            }
            $query  = '?' . http_build_query($params);
            $result = $this->request($server, $token, 'GET', $path . $query, null);
            if (!$result['success']) {
                $results[$path] = 'HTTP ' . $result['status'] . ': ' . substr($result['error'], 0, 120);
            } else {
                $data = json_decode($result['body'], true);
                $results[$path] = $data['_embedded']['items'][0] ?? $data;
            }
        }

        return $results;
    }

    /**
     * Pulls authentication sessions from ClearPass with acctstarttime greater than
     * the latest record already stored. On first run, fetches all sessions.
     * Also refreshes the status of any sessions still marked Active in the database.
     *
     * @return array{imported: int, updated: int, errors: string[]}
     */
    public function pullFromServer(ClearpassServer $server): array
    {
        $serverId  = $server->getId();
        $token     = $this->getAccessToken($server);
        $imported  = 0;
        $errors    = [];
        $offset    = 0;
        $latestTs  = $this->logRepo->findLatestAuthTimestamp($server);

        $params = ['sort' => '+acctstarttime', 'limit' => self::PAGE_SIZE];
        if ($latestTs !== null) {
            $params['filter'] = json_encode(['acctstarttime' => ['$gt' => (string) $latestTs->getTimestamp()]]);
        }

        do {
            $query  = '?' . http_build_query(array_merge($params, ['offset' => $offset]));
            $result = $this->request($server, $token, 'GET', '/api/v1/session' . $query, null);

            if (!$result['success']) {
                $errors[] = 'Fetch failed (offset ' . $offset . '): ' . $result['error'];
                break;
            }

            $data  = json_decode($result['body'], true);
            $items = $data['_embedded']['items'] ?? [];

            if (!empty($items)) {
                // Bulk-check which session IDs already exist (one query per page, no entity loading).
                $pageSessionIds = array_values(array_filter(array_map(
                    fn($i) => (string) ($i['id'] ?? ''), $items
                )));
                $existingIds = array_flip($this->logRepo->findExistingSessionIds($server, $pageSessionIds));

                // Bulk-fetch interfaces for all MACs on this page (one query, keyed by MAC).
                $pageMacs = array_unique(array_filter(array_map(
                    fn($i) => $this->normaliseMac((string) ($i['mac_address'] ?? $i['callingstationid'] ?? '')),
                    $items
                )));
                $ifaceMap = $this->ifaceRepo->findByMacs($pageMacs);

                foreach ($items as $item) {
                    $sessionId = (string) ($item['id'] ?? '');
                    $macRaw    = (string) ($item['mac_address'] ?? $item['callingstationid'] ?? '');
                    $mac       = $this->normaliseMac($macRaw);

                    if ($sessionId === '' || $mac === '' || isset($existingIds[$sessionId])) {
                        continue;
                    }

                    $acctStart = $item['acctstarttime'] ?? null;
                    try {
                        $authTs = $acctStart !== null
                            ? (new \DateTimeImmutable())->setTimestamp((int) $acctStart)
                            : new \DateTimeImmutable();
                    } catch (\Throwable) {
                        $authTs = new \DateTimeImmutable();
                    }

                    $log = new ClearpassAuthLog($sessionId, $mac, $authTs);
                    $log->setClearpassServer($server);
                    $log->setIpAddress($item['framedipaddress'] ?? $item['ip_address'] ?? null ?: null);
                    $log->setUsername($item['username'] ?? null ?: null);
                    $log->setService($item['servicetype'] ?? $item['service_name'] ?? null ?: null);
                    $log->setAuthStatus($item['state'] ?? null ?: null);
                    $log->setAuthProtocol($item['nasporttype'] ?? null ?: null);
                    $log->setNasIp($item['nasipaddress'] ?? $item['nas_ip_address'] ?? null ?: null);
                    $log->setNasPortId($item['nasportid'] ?? null ?: null);
                    $log->setRole($item['arubauserrole'] ?? null ?: null);
                    $log->setVlan($item['arubauservlan'] ?? null ?: null);
                    $log->setNetworkInterface($ifaceMap[$mac] ?? null);

                    $this->em->persist($log);
                    $imported++;
                }

                // Flush and clear the identity map between pages so memory stays flat.
                $this->em->flush();
                $this->em->clear();

                // Re-fetch the server entity after clear so it is managed again.
                $server = $this->em->find(ClearpassServer::class, $serverId);
            }

            $total  = $data['count'] ?? count($items);
            $offset += count($items);
        } while (count($items) === self::PAGE_SIZE && $offset < $total);

        $updated = $this->updateActiveSessions($server, $token, $errors);

        return ['imported' => $imported, 'updated' => $updated, 'errors' => $errors];
    }

    /**
     * Fetches the current state from ClearPass for every session we still have marked
     * Active, and bulk-updates authStatus for any that have since ended.
     * Uses scalar queries and DQL UPDATE — no entities are loaded into memory.
     */
    private function updateActiveSessions(ClearpassServer $server, string $token, array &$errors): int
    {
        // Returns plain arrays: [['id' => 123, 'sessionId' => 'abc'], ...]
        $activeSessions = $this->logRepo->findActiveSessionData($server);
        if (empty($activeSessions)) {
            return 0;
        }

        $updated = 0;

        foreach (array_chunk($activeSessions, 200) as $batch) {
            // Map sessionId => database row id (no entity objects).
            $bySessionId = array_column($batch, 'id', 'sessionId');

            $filter = json_encode(['id' => ['$in' => array_keys($bySessionId)]]);
            $query  = '?' . http_build_query(['filter' => $filter, 'limit' => count($bySessionId)]);
            $result = $this->request($server, $token, 'GET', '/api/v1/session' . $query, null);

            if (!$result['success']) {
                $errors[] = 'Active-session refresh failed: ' . $result['error'];
                continue;
            }

            $data  = json_decode($result['body'], true);
            $items = $data['_embedded']['items'] ?? [];

            // Group row IDs by the new status they should receive.
            $toUpdate = [];
            foreach ($items as $item) {
                $sessionId = (string) ($item['id'] ?? '');
                $newStatus = $item['state'] ?? null ?: null;

                if ($sessionId === '' || $newStatus === null || strtolower($newStatus) === 'active') {
                    continue;
                }

                $rowId = $bySessionId[$sessionId] ?? null;
                if ($rowId === null) {
                    continue;
                }

                $toUpdate[$newStatus][] = $rowId;
            }

            // One DQL UPDATE per distinct new status value — no entities loaded.
            foreach ($toUpdate as $status => $ids) {
                $this->em->createQueryBuilder()
                    ->update(ClearpassAuthLog::class, 'l')
                    ->set('l.authStatus', ':status')
                    ->where('l.id IN (:ids)')
                    ->setParameter('status', $status)
                    ->setParameter('ids', $ids)
                    ->getQuery()
                    ->execute();

                $updated += count($ids);
            }
        }

        return $updated;
    }

    private function getAccessToken(ClearpassServer $server): string
    {
        $result = $this->request($server, null, 'POST', '/api/oauth', [
            'grant_type'    => 'client_credentials',
            'client_id'     => $server->getClientId(),
            'client_secret' => $server->getClientSecret(),
        ]);

        if (!$result['success']) {
            throw new \RuntimeException('ClearPass OAuth failed: ' . $result['error']);
        }

        $data = json_decode($result['body'], true);
        if (empty($data['access_token'])) {
            throw new \RuntimeException('ClearPass OAuth response missing access_token');
        }

        return $data['access_token'];
    }

    private function request(
        ClearpassServer $server,
        ?string $token,
        string $method,
        string $path,
        ?array $body,
    ): array {
        $url     = $server->getApiUrl() . $path;
        $payload = $body !== null ? json_encode($body) : null;

        $headers = "Content-Type: application/json\r\nAccept: application/json\r\n";
        if ($token !== null) {
            $headers .= 'Authorization: Bearer ' . $token . "\r\n";
        }
        if ($payload !== null) {
            $headers .= 'Content-Length: ' . strlen($payload) . "\r\n";
        }

        $httpOptions = [
            'method'        => $method,
            'header'        => $headers,
            'timeout'       => 30,
            'ignore_errors' => true,
        ];
        if ($payload !== null) {
            $httpOptions['content'] = $payload;
        }

        $sslOptions = [];
        if (!$server->isVerifyTls()) {
            $sslOptions['verify_peer']      = false;
            $sslOptions['verify_peer_name'] = false;
        }

        $context  = stream_context_create(['http' => $httpOptions, 'ssl' => $sslOptions]);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return ['success' => false, 'error' => 'Connection failed to ' . $url, 'body' => '', 'status' => 0];
        }

        $status = 0;
        foreach ($http_response_header as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) {
                $status = (int) $m[1];
            }
        }

        $success = $status >= 200 && $status < 300;

        return [
            'success' => $success,
            'error'   => $success ? '' : 'HTTP ' . $status . ': ' . substr($response, 0, 200),
            'body'    => $response,
            'status'  => $status,
        ];
    }

    private function normaliseMac(string $mac): string
    {
        $hex = preg_replace('/[^0-9a-fA-F]/', '', $mac);
        if (strlen($hex) !== 12) {
            return '';
        }
        return implode(':', str_split(strtolower($hex), 2));
    }
}
