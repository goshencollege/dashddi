<?php

namespace App\Service;

use App\Entity\DnsServer;
use App\Repository\DomainRepository;
use App\Repository\SubnetRepository;

class DhcpDdnsConfigGenerator
{
    public function __construct(
        private readonly DomainRepository $domainRepo,
        private readonly SubnetRepository $subnetRepo,
    ) {}

    /**
     * Generates the full kea-dhcp-ddns.conf content as a PHP array (JSON-encode it for the file).
     *
     * Forward zones come from Domain entities with ddnsEnabled + ddnsDnsServer configured.
     * Reverse zones come from Subnet entities with ddnsEnabled + ddnsDnsServer configured.
     */
    public function generateConfig(): array
    {
        $tsigKeys       = [];
        $forwardDomains = [];
        $reverseDomains = [];

        foreach ($this->domainRepo->findBy(['ddnsEnabled' => true]) as $domain) {
            $server = $domain->getDdnsDnsServer();
            if (!$server || !$server->getDdnsAlgorithm() || !$server->getDdnsSecret()) {
                continue;
            }
            $this->collectKey($tsigKeys, $server);
            $forwardDomains[] = [
                'name'        => rtrim($domain->getName(), '.') . '.',
                'key-name'    => $server->getDdnsKeyName(),
                'dns-servers' => [['ip-address' => $server->getHostname(), 'port' => 53]],
            ];
        }

        foreach ($this->subnetRepo->findBy(['ddnsEnabled' => true]) as $subnet) {
            $server = $subnet->getDdnsDnsServer();
            if (!$server || !$server->getDdnsAlgorithm() || !$server->getDdnsSecret()) {
                continue;
            }
            $reverseZone = $subnet->getReverseZoneName();
            if (!$reverseZone) {
                continue;
            }
            $this->collectKey($tsigKeys, $server);
            $reverseDomains[] = [
                'name'        => rtrim($reverseZone, '.') . '.',
                'key-name'    => $server->getDdnsKeyName(),
                'dns-servers' => [['ip-address' => $server->getHostname(), 'port' => 53]],
            ];
        }

        return [
            'DhcpDdns' => [
                'ip-address'   => '127.0.0.1',
                'port'         => 53001,
                'tsig-keys'    => array_values($tsigKeys),
                'forward-ddns' => ['ddns-domains' => $forwardDomains],
                'reverse-ddns' => ['ddns-domains' => $reverseDomains],
                'loggers'      => [[
                    'name'           => 'kea-dhcp-ddns',
                    'output_options' => [['output' => 'syslog']],
                    'severity'       => 'INFO',
                ]],
            ],
        ];
    }

    private function collectKey(array &$collected, DnsServer $server): void
    {
        $name = $server->getDdnsKeyName();
        if (!isset($collected[$name])) {
            $collected[$name] = [
                'name'      => $name,
                'algorithm' => $server->getDdnsAlgorithm()->keaName(),
                'secret'    => $server->getDdnsSecret(),
            ];
        }
    }
}
