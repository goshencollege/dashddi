<?php

namespace App\Tests\Unit\Service;

use App\Service\EncryptionService;
use PHPUnit\Framework\TestCase;

class EncryptionServiceTest extends TestCase
{
    private EncryptionService $service;

    protected function setUp(): void
    {
        $key = sodium_bin2base64(
            random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES),
            SODIUM_BASE64_VARIANT_ORIGINAL
        );
        $this->service = new EncryptionService($key);
    }

    public function testEncryptAddsPrefix(): void
    {
        $this->assertStringStartsWith('enc:', $this->service->encrypt('hello'));
    }

    public function testDecryptRoundtrip(): void
    {
        $plain = 'super-secret-password';
        $this->assertSame($plain, $this->service->decrypt($this->service->encrypt($plain)));
    }

    public function testDecryptEmptyStringRoundtrip(): void
    {
        $this->assertSame('', $this->service->decrypt($this->service->encrypt('')));
    }

    public function testIsEncryptedTrueForEncryptedValue(): void
    {
        $this->assertTrue($this->service->isEncrypted($this->service->encrypt('x')));
    }

    public function testIsEncryptedFalseForPlaintext(): void
    {
        $this->assertFalse($this->service->isEncrypted('plain-text'));
    }

    public function testIsEncryptedFalseForEmptyString(): void
    {
        $this->assertFalse($this->service->isEncrypted(''));
    }

    public function testDecryptPlaintextPassthrough(): void
    {
        $this->assertSame('plain-text', $this->service->decrypt('plain-text'));
    }

    public function testDecryptEmptyPlaintextPassthrough(): void
    {
        $this->assertSame('', $this->service->decrypt(''));
    }

    public function testInvalidKeyLengthThrows(): void
    {
        $shortKey = sodium_bin2base64('tooshort', SODIUM_BASE64_VARIANT_ORIGINAL);
        $this->expectException(\InvalidArgumentException::class);
        new EncryptionService($shortKey);
    }

    public function testDecryptCorruptDataThrows(): void
    {
        $fakePayload = str_repeat("\x00", SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 32);
        $corrupt = 'enc:' . sodium_bin2base64($fakePayload, SODIUM_BASE64_VARIANT_ORIGINAL);
        $this->expectException(\RuntimeException::class);
        $this->service->decrypt($corrupt);
    }

    public function testEncryptProducesDifferentCiphertextsEachTime(): void
    {
        $enc1 = $this->service->encrypt('hello');
        $enc2 = $this->service->encrypt('hello');
        $this->assertNotSame($enc1, $enc2);
    }

    public function testDifferentKeyCannotDecrypt(): void
    {
        $encrypted = $this->service->encrypt('secret');

        $otherKey = sodium_bin2base64(
            random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES),
            SODIUM_BASE64_VARIANT_ORIGINAL
        );
        $otherService = new EncryptionService($otherKey);

        $this->expectException(\RuntimeException::class);
        $otherService->decrypt($encrypted);
    }
}
