<?php

namespace App\Service;

use App\Entity\SshHostKey;
use App\Repository\HostRepository;
use Doctrine\ORM\EntityManagerInterface;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

class SshKeyService
{
    public function __construct(
        private readonly HostRepository $hostRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function generateKeyPair(): array
    {
        $key = EC::createKey('Ed25519');

        return [
            'private' => $key->toString('OpenSSH'),
            'public'  => $key->getPublicKey()->toString('OpenSSH'),
        ];
    }

    public function extractPublicKey(string $privateKey): string
    {
        return PublicKeyLoader::load($privateKey)->getPublicKey()->toString('OpenSSH');
    }

    public function connect(string $hostname, string $user, string $privateKey): SFTP
    {
        $key  = PublicKeyLoader::load($privateKey);
        $sftp = new SFTP($hostname);

        if (!$sftp->login($user, $key)) {
            throw new \RuntimeException("SSH login failed for $user@$hostname");
        }

        $this->verifyAndLearnHostKey($sftp, $hostname);

        return $sftp;
    }

    /**
     * TOFU host-key verification. On first connect to a known host, stores the presented key.
     * On subsequent connects, throws if the key has changed.
     * Safe to call on any SSH2/SFTP instance after a successful login.
     */
    public function verifyAndLearnHostKey(SSH2 $ssh, string $target): void
    {
        try {
            $presentedKey = $ssh->getServerPublicHostKey();
        } catch (\Throwable) {
            return;
        }

        if ($presentedKey === false) {
            return;
        }

        $host = $this->hostRepository->findByConnectionTarget($target);
        if ($host === null) {
            return;
        }

        $presented = explode(' ', $presentedKey, 3);
        $algorithm = $presented[0] ?? '';
        $keyData   = $presented[1] ?? '';

        if ($algorithm === '' || $keyData === '') {
            return;
        }

        $stored = $host->getSshHostKeyByAlgorithm($algorithm);

        if ($stored === null) {
            $entry = (new SshHostKey())
                ->setHost($host)
                ->setAlgorithm($algorithm)
                ->setPublicKey($algorithm . ' ' . $keyData);
            $this->em->persist($entry);
            $this->em->flush();
            return;
        }

        $storedParts = explode(' ', $stored->getPublicKey(), 3);
        if (($storedParts[1] ?? '') !== $keyData) {
            throw new \RuntimeException(sprintf(
                'SSH host key mismatch for %s (algorithm: %s) — expected %s. ' .
                'If the server key changed legitimately, remove the stored key from the host page in DashDDI.',
                $target,
                $algorithm,
                $stored->getFingerprint() ?? 'unknown'
            ));
        }
    }

    public function testConnection(string $hostname, string $user, string $privateKey): array
    {
        if (!defined('NET_SSH2_LOGGING')) {
            define('NET_SSH2_LOGGING', SFTP::LOG_SIMPLE);
        }

        $result = [
            'success'              => false,
            'serverIdentification' => null,
            'errors'               => [],
            'authMethods'          => null,
            'log'                  => [],
        ];

        try {
            $key  = PublicKeyLoader::load($privateKey);
            $sftp = new SFTP($hostname);

            $result['success']              = $sftp->login($user, $key);
            $result['serverIdentification'] = $sftp->getServerIdentification() ?: null;
            $result['errors']               = $sftp->getErrors();
            $result['authMethods']          = $sftp->getAuthMethodsToContinue();
            $result['log']                  = (array) ($sftp->getLog() ?: []);
        } catch (\Throwable $e) {
            $result['errors'][] = $e->getMessage();
        }

        return $result;
    }
}
