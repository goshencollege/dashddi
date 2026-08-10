<?php

namespace App\Service;

use App\Entity\DnssecDisableRequest;
use App\Entity\DnsServer;
use App\Enum\DnssecDisableStatus;
use phpseclib3\Net\SFTP;

/**
 * All commands that touch bind-owned key files run under "sudo -u bind".
 * All rndc commands run under "sudo" (as root). Same sudoers entries as
 * KskRolloverService — see that class for the required lines.
 */
class DnssecDisableService
{
    public function __construct(
        private readonly SshKeyService $sshKeys,
    ) {}

    /**
     * Marks every published KSK/ZSK for the zone inactive+delete immediately,
     * then reloads and re-signs so BIND drops their DNSKEY/RRSIG records.
     * Updates the request entity but does NOT flush — caller is responsible.
     */
    public function retireAllKeys(DnssecDisableRequest $request): void
    {
        $sftp   = $this->connect($request);
        $zone   = $request->getZoneName();
        $keyDir = rtrim((string) $request->getEffectiveKeyDirectory(), '/');

        if ($keyDir === '') {
            throw new \RuntimeException('No key directory available for this zone.');
        }

        $out = (string) $sftp->exec(sprintf('dig +short DNSKEY %s 2>/dev/null', escapeshellarg($zone)));

        $keyFiles = [];
        foreach (explode("\n", $out) as $line) {
            $line  = trim($line);
            $parts = preg_split('/\s+/', $line, 4);
            if (!isset($parts[3])) {
                continue;
            }
            [$flagStr, $protoStr, $algStr, $pubKey] = $parts;
            $flags = (int) $flagStr;
            if ($flags !== 257 && $flags !== 256) {
                continue; // not a KSK or ZSK
            }
            $alg        = (int) $algStr;
            $tag        = $this->computeKeyTag($flags, (int) $protoStr, $alg, $pubKey);
            $keyFiles[] = sprintf('K%s.+%03d+%05d', $zone, $alg, $tag);
        }
        $keyFiles = array_values(array_unique($keyFiles));

        if (empty($keyFiles)) {
            $request->addLog('No published DNSKEY records found — nothing to retire.');
            $request->setRetiredKeys([]);
            $request->setStatus(DnssecDisableStatus::KeysRetired);
            return;
        }

        foreach ($keyFiles as $file) {
            $out = $sftp->exec(sprintf(
                'sudo -u bind dnssec-settime -I now -D now %s 2>&1',
                escapeshellarg($keyDir . '/' . $file . '.key')
            ));
            $request->addLog("dnssec-settime ($file): " . trim((string) $out));
        }

        foreach ($this->zoneViewNames($request) as $view) {
            $out = $sftp->exec('sudo rndc loadkeys ' . escapeshellarg($zone) . ' IN ' . escapeshellarg($view) . ' 2>&1');
            $request->addLog("rndc loadkeys ($view): " . trim((string) $out));

            $out = $sftp->exec('sudo rndc sign ' . escapeshellarg($zone) . ' IN ' . escapeshellarg($view) . ' 2>&1');
            $request->addLog("rndc sign ($view): " . trim((string) $out));
        }

        $request->setRetiredKeys($keyFiles);
        $request->addLog(sprintf(
            '%d key(s) marked inactive+delete now. BIND will drop DNSKEY/RRSIG records on the next zone maintenance cycle.',
            count($keyFiles)
        ));
        $request->setStatus(DnssecDisableStatus::KeysRetired);
    }

    // -------------------------------------------------------------------------

    private function connect(DnssecDisableRequest $request): SFTP
    {
        $server = $request->getDnsServer();
        if (!$server) {
            throw new \RuntimeException('No DNS server associated with this disable request.');
        }
        return $this->connectServer($server);
    }

    private function connectServer(DnsServer $server): SFTP
    {
        if (!$server->getSshPrivateKey()) {
            throw new \RuntimeException('No SSH key configured for server "' . $server->getName() . '".');
        }
        return $this->sshKeys->connect($server->getHostname(), $server->getSshUser(), $server->getSshPrivateKey());
    }

    /** Computes the DNSSEC key tag from DNSKEY RDATA per RFC 4034 Appendix B. */
    private function computeKeyTag(int $flags, int $protocol, int $alg, string $pubKeyB64): int
    {
        $rdata = pack('n', $flags) . chr($protocol) . chr($alg) . base64_decode($pubKeyB64);
        $ac    = 0;
        for ($i = 0, $len = strlen($rdata); $i < $len; $i++) {
            $ac += ($i & 1) ? ord($rdata[$i]) : (ord($rdata[$i]) << 8);
        }
        $ac += ($ac >> 16) & 0xFFFF;
        return $ac & 0xFFFF;
    }

    /**
     * Returns the view names that the zone belongs to on the request's DNS server.
     *
     * @return string[]
     */
    private function zoneViewNames(DnssecDisableRequest $request): array
    {
        $server = $request->getDnsServer();
        if (!$server) {
            return [];
        }

        $serverViewIds = [];
        foreach ($server->getViews() as $v) {
            $serverViewIds[$v->getId()] = $v->getName();
        }

        $names = [];
        foreach ($request->getEffectiveViews() as $v) {
            if (isset($serverViewIds[$v->getId()])) {
                $names[] = $v->getName();
            }
        }

        return $names;
    }
}
