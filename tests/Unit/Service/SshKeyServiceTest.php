<?php

namespace App\Tests\Unit\Service;

use App\Service\SshKeyService;
use PHPUnit\Framework\TestCase;

class SshKeyServiceTest extends TestCase
{
    private SshKeyService $service;

    protected function setUp(): void
    {
        $this->service = new SshKeyService();
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
}
