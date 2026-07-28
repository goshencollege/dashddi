<?php

namespace App\Service;

use App\Entity\ClearpassServer;
use App\Entity\NetworkInterface;
use App\Repository\NetworkInterfaceRepository;

class ClearpassDeployService
{
    private const MANAGED_BY_KEY   = 'Managed By';
    private const MANAGED_BY_VALUE = 'DashDDI';
    private const PAGE_SIZE        = 1000;

    public function __construct(
        private readonly NetworkInterfaceRepository $interfaceRepo,
    ) {}

    public function pushSingleInterface(ClearpassServer $server, string $mac): array
    {
        $mac   = $this->normaliseMac($mac);
        $iface = $this->interfaceRepo->findActiveByMac($mac);
        $token = $this->getAccessToken($server);

        if ($iface === null) {
            // Interface was deleted — remove from ClearPass if we manage it
            $get = $this->request($server, $token, 'GET', '/api/endpoint/mac-address/' . rawurlencode($mac), null);
            if (!$get['success']) {
                // Already gone or never existed — treat as success
                return ['success' => true, 'action' => 'deleted', 'mac' => $mac, 'error' => '', 'response' => ''];
            }
            $data   = json_decode($get['body'], true);
            $attrs  = $data['attributes'] ?? [];
            if (($attrs[self::MANAGED_BY_KEY] ?? '') !== self::MANAGED_BY_VALUE) {
                return ['success' => true, 'action' => 'skipped', 'mac' => $mac, 'error' => '', 'response' => ''];
            }
            $del = $this->request($server, $token, 'DELETE', '/api/endpoint/' . $data['id'], null);
            return ['success' => $del['success'], 'action' => 'deleted', 'mac' => $mac, 'error' => $del['error'], 'response' => $del['body']];
        }

        $payload = $this->buildEndpointPayload($iface);
        $macPath = '/api/endpoint/mac-address/' . rawurlencode($mac);

        $patch = $this->request($server, $token, 'PATCH', $macPath, $payload);

        if ($patch['success']) {
            $result = $patch;
            $verb   = 'updated';
        } elseif ($patch['status'] === 404) {
            $result = $this->request($server, $token, 'POST', '/api/endpoint', $payload);
            $verb   = 'created';
        } else {
            $result = $patch;
            $verb   = 'failed';
        }

        return [
            'success'  => $result['success'],
            'action'   => $verb,
            'mac'      => $mac,
            'error'    => $result['error'],
            'response' => $result['body'],
        ];
    }

    public function deployToServer(ClearpassServer $server): array
    {
        $token     = $this->getAccessToken($server);
        $existing  = $this->fetchManagedEndpoints($server, $token);
        $interfaces = $this->interfaceRepo->findAllForRadiusAuth();

        $created  = 0;
        $updated  = 0;
        $deleted  = 0;
        $errors   = [];
        $seenMacs = [];

        foreach ($interfaces as $iface) {
            $mac          = $iface->getMacAddress();
            $seenMacs[$mac] = true;
            $payload      = $this->buildEndpointPayload($iface);

            $macPath = '/api/endpoint/mac-address/' . rawurlencode($mac);
            $patch   = $this->request($server, $token, 'PATCH', $macPath, $payload);

            if ($patch['success']) {
                $updated++;
            } elseif ($patch['status'] === 404) {
                $post = $this->request($server, $token, 'POST', '/api/endpoint', $payload);
                if ($post['success']) {
                    $created++;
                } else {
                    $errors[] = 'Create ' . $mac . ': ' . $post['error'];
                }
            } else {
                $errors[] = 'Update ' . $mac . ': ' . $patch['error'];
            }
        }

        foreach ($existing as $mac => $ep) {
            if (!isset($seenMacs[$mac])) {
                $result = $this->request($server, $token, 'DELETE', '/api/endpoint/' . $ep['id'], null);
                if ($result['success']) {
                    $deleted++;
                } else {
                    $errors[] = 'Delete ' . $mac . ': ' . $result['error'];
                }
            }
        }

        return [
            'success' => empty($errors),
            'created' => $created,
            'updated' => $updated,
            'deleted' => $deleted,
            'errors'  => $errors,
        ];
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

    /** @return array<string, array{id: int}> keyed by normalised MAC */
    private function fetchManagedEndpoints(ClearpassServer $server, string $token): array
    {
        $managed = [];
        $offset  = 0;
        $filter  = json_encode([self::MANAGED_BY_KEY => self::MANAGED_BY_VALUE]);

        do {
            $query  = '?' . http_build_query(['filter' => $filter, 'limit' => self::PAGE_SIZE, 'offset' => $offset]);
            $result = $this->request($server, $token, 'GET', '/api/endpoint' . $query, null);

            if (!$result['success']) {
                throw new \RuntimeException('Failed to fetch ClearPass endpoints: ' . $result['error']);
            }

            $data  = json_decode($result['body'], true);
            $items = $data['_embedded']['items'] ?? [];

            foreach ($items as $item) {
                $mac = $this->normaliseMac($item['mac_address'] ?? '');
                if ($mac !== '') {
                    $managed[$mac] = ['id' => $item['id']];
                }
            }

            $total  = $data['count'] ?? count($items);
            $offset += count($items);
        } while (count($items) === self::PAGE_SIZE && $offset < $total);

        return $managed;
    }

    private function buildEndpointPayload(NetworkInterface $iface): array
    {
        $attrs = [self::MANAGED_BY_KEY => self::MANAGED_BY_VALUE];

        if ($host = $iface->getHost()) {
            $attrs['Device Name'] = $host->getName();

            $tagNames = array_map(fn($t) => $t->getName(), $host->getTags()->toArray());
            if (!empty($tagNames)) {
                sort($tagNames);
                $attrs['Tags'] = '|' . implode('|', $tagNames) . '|';
            }
        }

        if ($hostname = $iface->getPrimaryName()) {
            $attrs['Hostname'] = $hostname;
        }

        if ($ip = $iface->getIpAddress()?->getAddress()) {
            $attrs['IP Address'] = $ip;
        }

        if ($ipv6 = $iface->getIpv6Address()?->getAddress()) {
            $attrs['IPv6 Address'] = $ipv6;
        }

        if ($subnet = $iface->getSubnet()) {
            if ($cidr = $subnet->getIpv4Cidr()) {
                $attrs['Subnet'] = $cidr;
            }
            if ($vlan = $subnet->getVlan()) {
                $attrs['VLAN ID'] = (string) $vlan;
            }
        }

        return [
            'mac_address' => $iface->getMacAddress(),
            'status'      => 'Known',
            'attributes'  => $attrs,
        ];
    }

    private function request(
        ClearpassServer $server,
        ?string $token,
        string $method,
        string $path,
        ?array $body,
    ): array {
        $url     = $server->getApiUrl() . $path;
        if (strtolower(parse_url($url, PHP_URL_SCHEME) ?? '') !== 'https') {
            throw new \RuntimeException('ClearPass API URL must use HTTPS.');
        }
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
            return ['success' => false, 'error' => 'Connection failed to ' . $url, 'body' => ''];
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
