<?php

namespace App\Service;

use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

class SshKeyService
{
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

        return $sftp;
    }
}
