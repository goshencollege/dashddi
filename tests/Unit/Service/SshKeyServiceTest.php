<?php

namespace App\Tests\Unit\Service;

use App\Entity\Host;
use App\Entity\SshHostKey;
use App\Repository\HostRepository;
use App\Service\SshKeyService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use phpseclib3\Net\SSH2;
use PHPUnit\Framework\TestCase;

class SshKeyServiceTest extends TestCase
{
    private SshKeyService $service;

    protected function setUp(): void
    {
        $this->service = new SshKeyService(
            $this->createStub(HostRepository::class),
            $this->createStub(EntityManagerInterface::class),
        );
    }

    public function testGenerateKeyPairReturnsBothKeys(): void
    {
        $pair = $this->service->generateKeyPair();

        $this->assertArrayHasKey('private', $pair);
        $this->assertArrayHasKey('public', $pair);
        $this->assertStringContainsString('OPENSSH PRIVATE KEY', $pair['private']);
        $this->assertStringStartsWith('ssh-ed25519 ', $pair['public']);
    }

    public function testGenerateKeyPairProducesUniqueKeys(): void
    {
        $pair1 = $this->service->generateKeyPair();
        $pair2 = $this->service->generateKeyPair();

        $this->assertNotSame($pair1['private'], $pair2['private']);
        $this->assertNotSame($pair1['public'], $pair2['public']);
    }

    public function testExtractPublicKeyMatchesGeneratedKey(): void
    {
        $pair = $this->service->generateKeyPair();
        $extracted = $this->service->extractPublicKey($pair['private']);

        // Compare key type and base64 material (ignore optional trailing comment)
        [$expectedType, $expectedMaterial] = explode(' ', trim($pair['public']), 3);
        [$extractedType, $extractedMaterial] = explode(' ', trim($extracted), 3);

        $this->assertSame($expectedType, $extractedType);
        $this->assertSame($expectedMaterial, $extractedMaterial);
    }

    public function testExtractedPublicKeyIsEd25519(): void
    {
        $pair = $this->service->generateKeyPair();
        $public = $this->service->extractPublicKey($pair['private']);

        $this->assertStringStartsWith('ssh-ed25519 ', $public);
    }

    // --- verifyAndLearnHostKey ---

    public function testVerifyAndLearnSkipsWhenKeyReturnsFalse(): void
    {
        $ssh = $this->createStub(SSH2::class);
        $ssh->method('getServerPublicHostKey')->willReturn(false);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $service = new SshKeyService($this->createStub(HostRepository::class), $em);
        $service->verifyAndLearnHostKey($ssh, '10.0.0.1');
        $this->addToAssertionCount(1);
    }

    public function testVerifyAndLearnSkipsUnknownHost(): void
    {
        $ssh = $this->createStub(SSH2::class);
        $ssh->method('getServerPublicHostKey')->willReturn('ssh-ed25519 AAAAC3Nz');

        $repo = $this->createStub(HostRepository::class);
        $repo->method('findByConnectionTarget')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $service = new SshKeyService($repo, $em);
        $service->verifyAndLearnHostKey($ssh, '10.0.0.1');
        $this->addToAssertionCount(1);
    }

    public function testVerifyAndLearnPersistsNewKey(): void
    {
        $keyString = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBGkTest';

        $ssh = $this->createStub(SSH2::class);
        $ssh->method('getServerPublicHostKey')->willReturn($keyString);

        $repo = $this->createStub(HostRepository::class);
        $repo->method('findByConnectionTarget')->willReturn(new Host());

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')
            ->with($this->isInstanceOf(SshHostKey::class));
        $em->expects($this->once())->method('flush');

        $service = new SshKeyService($repo, $em);
        $service->verifyAndLearnHostKey($ssh, '10.0.0.1');
    }

    public function testVerifyAndLearnAcceptsMatchingStoredKey(): void
    {
        $keyString = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBGkTest';

        $ssh = $this->createStub(SSH2::class);
        $ssh->method('getServerPublicHostKey')->willReturn($keyString);

        $host = new Host();
        $storedKey = (new SshHostKey())
            ->setHost($host)
            ->setAlgorithm('ssh-ed25519')
            ->setPublicKey($keyString);
        $prop = new \ReflectionProperty(Host::class, 'sshHostKeys');
        $prop->setValue($host, new ArrayCollection([$storedKey]));

        $repo = $this->createStub(HostRepository::class);
        $repo->method('findByConnectionTarget')->willReturn($host);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $service = new SshKeyService($repo, $em);
        $service->verifyAndLearnHostKey($ssh, '10.0.0.1');
        $this->addToAssertionCount(1);
    }

    public function testVerifyAndLearnThrowsOnKeyMismatch(): void
    {
        $presentedKey = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBGkNew';
        $storedKeyStr = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBGkOld';

        $ssh = $this->createStub(SSH2::class);
        $ssh->method('getServerPublicHostKey')->willReturn($presentedKey);

        $host = new Host();
        $storedKey = (new SshHostKey())
            ->setHost($host)
            ->setAlgorithm('ssh-ed25519')
            ->setPublicKey($storedKeyStr);
        $prop = new \ReflectionProperty(Host::class, 'sshHostKeys');
        $prop->setValue($host, new ArrayCollection([$storedKey]));

        $repo = $this->createStub(HostRepository::class);
        $repo->method('findByConnectionTarget')->willReturn($host);

        $service = new SshKeyService($repo, $this->createStub(EntityManagerInterface::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/host key mismatch/i');
        $service->verifyAndLearnHostKey($ssh, '10.0.0.1');
    }

    // --- SshHostKey::getFingerprint ---

    public function testFingerprintStartsWithSHA256Prefix(): void
    {
        $pair = $this->service->generateKeyPair();
        $key = (new SshHostKey())->setPublicKey($pair['public']);

        $this->assertStringStartsWith('SHA256:', (string) $key->getFingerprint());
    }

    public function testFingerprintIsNullForMalformedKey(): void
    {
        $key = (new SshHostKey())->setPublicKey('not-valid');

        $this->assertNull($key->getFingerprint());
    }

    public function testFingerprintIsDeterministic(): void
    {
        $pair = $this->service->generateKeyPair();
        $key = (new SshHostKey())->setPublicKey($pair['public']);

        $this->assertSame($key->getFingerprint(), $key->getFingerprint());
    }
}
