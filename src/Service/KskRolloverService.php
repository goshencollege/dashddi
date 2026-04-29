<?php

namespace App\Service;

use App\Entity\DnssecKskRollover;
use App\Enum\KskRolloverStatus;
use phpseclib3\Net\SFTP;

class KskRolloverService
{
    public function __construct(
        private readonly SshKeyService $sshKeys,
    ) {}

    /**
     * Runs SSH operations for step 1: generate new KSK + rndc loadkeys.
     * Updates the rollover entity but does NOT flush — caller is responsible.
     */
    public function startRollover(DnssecKskRollover $rollover): void
    {
        $sftp      = $this->connect($rollover);
        $zone      = $rollover->getDomain()->getName();
        $keyDir    = rtrim($rollover->getKeyDirectory(), '/');
        $algorithm = $rollover->getAlgorithm();

        $oldFile = $this->findCurrentKsk($sftp, $keyDir, $zone);
        if ($oldFile) {
            $rollover->setOldKeyFile($oldFile);
            $rollover->setOldKeyTag($this->tagFromFile($oldFile));
            $rollover->addLog("Found existing KSK: $oldFile");
        } else {
            $rollover->addLog('No existing KSK found — generating fresh.');
        }

        $out = $sftp->exec(sprintf(
            'cd %s && dnssec-keygen -a %s -f KSK %s 2>&1',
            escapeshellarg($keyDir),
            escapeshellarg($algorithm),
            escapeshellarg($zone)
        ));
        $exit = $sftp->getExitStatus();
        $rollover->addLog("dnssec-keygen: " . trim((string)$out));
        if ($exit !== 0) {
            throw new \RuntimeException("dnssec-keygen failed: " . trim((string)$out));
        }

        // dnssec-keygen prints progress dots + key base name as the last line
        $lines   = array_values(array_filter(array_map('trim', explode("\n", (string)$out))));
        $newFile = end($lines);
        if (!$newFile) {
            throw new \RuntimeException('dnssec-keygen produced no output (expected key base name).');
        }
        $rollover->setNewKeyFile($newFile);
        $rollover->setNewKeyTag($this->tagFromFile($newFile));

        $ttl = $this->probeDnskeyTtl($sftp, $zone);
        if ($ttl !== null) {
            $rollover->setDnskeyTtlSeconds($ttl);
        }

        $ds = $this->fetchDsRecord($sftp, $keyDir, $newFile);
        $rollover->setDsRecord($ds);
        $rollover->addLog("DS record obtained.");

        $rndcOut = $sftp->exec('rndc loadkeys ' . escapeshellarg($zone) . ' 2>&1');
        $rollover->addLog("rndc loadkeys: " . trim((string)$rndcOut));

        $rollover->setStatus(KskRolloverStatus::KeyPublished);
    }

    /**
     * Runs SSH operations for step 4: dnssec-settime + rndc sign.
     * Updates the rollover entity but does NOT flush — caller is responsible.
     */
    public function retireOldKey(DnssecKskRollover $rollover): void
    {
        $sftp    = $this->connect($rollover);
        $zone    = $rollover->getDomain()->getName();
        $keyDir  = rtrim($rollover->getKeyDirectory(), '/');
        $oldFile = $rollover->getOldKeyFile();

        if (!$oldFile) {
            throw new \RuntimeException('Old key file not set on rollover record.');
        }

        $ttl         = $rollover->getDnskeyTtlSeconds() ?? 3600;
        $deleteDelay = '+' . ($ttl * 2);

        $cmd = sprintf(
            'dnssec-settime -I now -D %s %s 2>&1',
            escapeshellarg($deleteDelay),
            escapeshellarg($keyDir . '/' . $oldFile . '.key')
        );
        $out  = $sftp->exec($cmd);
        $exit = $sftp->getExitStatus();
        $rollover->addLog("dnssec-settime: " . trim((string)$out));
        if ($exit !== 0) {
            throw new \RuntimeException("dnssec-settime failed: " . trim((string)$out));
        }

        $signOut = $sftp->exec('rndc sign ' . escapeshellarg($zone) . ' 2>&1');
        $rollover->addLog("rndc sign: " . trim((string)$signOut));

        $rollover->setStatus(KskRolloverStatus::OldKeyRetired);
    }

    // -------------------------------------------------------------------------

    private function connect(DnssecKskRollover $rollover): SFTP
    {
        $server = $rollover->getDnsServer();
        if (!$server) {
            throw new \RuntimeException('No DNS server associated with this rollover.');
        }
        if (!$server->getSshPrivateKey()) {
            throw new \RuntimeException('No SSH key configured for server "' . $server->getName() . '".');
        }
        return $this->sshKeys->connect($server->getHostname(), $server->getSshUser(), $server->getSshPrivateKey());
    }

    private function findCurrentKsk(SFTP $sftp, string $keyDir, string $zone): ?string
    {
        $out  = $sftp->exec(sprintf(
            "grep -l ' 257 ' %s/K%s.+*.key 2>/dev/null | head -1",
            $keyDir,
            $zone
        ));
        $path = trim((string)$out);
        if (!$path) {
            return null;
        }
        return basename($path, '.key') ?: null;
    }

    private function tagFromFile(string $fileBaseName): int
    {
        if (preg_match('/\+(\d+)$/', $fileBaseName, $m)) {
            return (int)$m[1];
        }
        return 0;
    }

    private function probeDnskeyTtl(SFTP $sftp, string $zone): ?int
    {
        $out = $sftp->exec(sprintf(
            "dig +noall +answer DNSKEY %s 2>/dev/null | awk '{print $2; exit}'",
            escapeshellarg($zone)
        ));
        $ttl = trim((string)$out);
        return is_numeric($ttl) ? (int)$ttl : null;
    }

    private function fetchDsRecord(SFTP $sftp, string $keyDir, string $keyFile): string
    {
        $out = $sftp->exec(sprintf(
            'dnssec-dsfromkey %s 2>&1',
            escapeshellarg($keyDir . '/' . $keyFile . '.key')
        ));
        return trim((string)$out);
    }
}
