<?php

namespace App\Service;

class FcrdnsChecker
{
    /**
     * Verifies that $fqdn resolves forward to at least one of the interface's IPs,
     * confirming Forward-Confirmed Reverse DNS (FCrDNS).
     *
     * Returns null on success, or a human-readable error string on failure.
     */
    public function check(string $fqdn, ?string $ipv4, ?string $ipv6): ?string
    {
        if ($ipv4 === null && $ipv6 === null) {
            return 'The interface has no IP address assigned; FCrDNS cannot be verified.';
        }

        $records = @dns_get_record($fqdn, DNS_A | DNS_AAAA);

        if ($records === false || empty($records)) {
            return sprintf('"%s" does not resolve in DNS — FCrDNS check failed.', $fqdn);
        }

        foreach ($records as $record) {
            $resolved = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($resolved === null) {
                continue;
            }
            if ($ipv4 !== null && $resolved === $ipv4) {
                return null;
            }
            if ($ipv6 !== null && $this->normalizeIpv6($resolved) === $this->normalizeIpv6($ipv6)) {
                return null;
            }
        }

        $resolvedAddresses = implode(', ', array_filter(array_map(
            fn($r) => $r['ip'] ?? $r['ipv6'] ?? null,
            $records
        )));

        return sprintf(
            '"%s" resolves to %s, not the interface\'s IP — FCrDNS check failed.',
            $fqdn,
            $resolvedAddresses ?: 'unknown'
        );
    }

    private function normalizeIpv6(string $ip): string|false
    {
        $packed = inet_pton($ip);
        return $packed !== false ? inet_ntop($packed) : $ip;
    }
}
