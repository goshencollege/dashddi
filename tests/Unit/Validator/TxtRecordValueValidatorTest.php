<?php

namespace App\Tests\Unit\Validator;

use App\Validator\TxtRecordValueValidator;
use PHPUnit\Framework\TestCase;

class TxtRecordValueValidatorTest extends TestCase
{
    // --- SPF ---

    public function testValidSpfPassesValidation(): void
    {
        $errors = TxtRecordValueValidator::validate('@', 'v=spf1 include:_spf.example.com ip4:192.0.2.0/24 -all');
        $this->assertEmpty($errors);
    }

    public function testSpfWithAllQualifiersPassesValidation(): void
    {
        $errors = TxtRecordValueValidator::validate('@', 'v=spf1 +mx -ip4:10.0.0.1 ~include:other.com ?all');
        $this->assertEmpty($errors);
    }

    public function testSpfWithRedirectModifierPassesValidation(): void
    {
        $errors = TxtRecordValueValidator::validate('@', 'v=spf1 redirect=_spf.example.com');
        $this->assertEmpty($errors);
    }

    public function testSpfMissingTerminatorFailsValidation(): void
    {
        $errors = TxtRecordValueValidator::validate('@', 'v=spf1 include:example.com');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('all', $errors[array_key_last($errors)]);
    }

    public function testSpfWithInvalidMechanismFailsValidation(): void
    {
        $errors = TxtRecordValueValidator::validate('@', 'v=spf1 inclide:example.com -all');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('inclide:example.com', $errors[0]);
    }

    public function testSpfV2NotDetectedAsSpf(): void
    {
        // v=spf2 is not SPF; treated as generic TXT with no structured validation
        $errors = TxtRecordValueValidator::validate('@', 'v=spf2 -all');
        $this->assertEmpty($errors);
    }

    // --- DKIM ---

    public function testValidDkimPassesValidation(): void
    {
        $errors = TxtRecordValueValidator::validate(
            'default._domainkey',
            'v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA'
        );
        $this->assertEmpty($errors);
    }

    public function testDkimEd25519KeyTypePassesValidation(): void
    {
        $errors = TxtRecordValueValidator::validate(
            'selector._domainkey',
            'v=DKIM1; k=ed25519; p=11qYAYKxCrfVS/7TyWQHOg7hcvPapiMlrwIaaPcHURo='
        );
        $this->assertEmpty($errors);
    }

    public function testDkimMissingVersionFailsValidation(): void
    {
        $errors = TxtRecordValueValidator::validate(
            'default._domainkey',
            'k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA'
        );
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('v=DKIM1', $errors[0]);
    }

    public function testDkimMissingPublicKeyTagFailsValidation(): void
    {
        $errors = TxtRecordValueValidator::validate(
            'selector._domainkey',
            'v=DKIM1; k=rsa'
        );
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('p=', $errors[0]);
    }

    public function testDkimInvalidKeyTypeFailsValidation(): void
    {
        $errors = TxtRecordValueValidator::validate(
            'selector._domainkey',
            'v=DKIM1; k=dsa; p=AAAA'
        );
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('k=', $errors[0]);
    }

    public function testDkimEmptyPublicKeyPassesValidation(): void
    {
        // Empty p= signals key revocation — valid per RFC 6376
        $errors = TxtRecordValueValidator::validate(
            'selector._domainkey',
            'v=DKIM1; p='
        );
        $this->assertEmpty($errors);
    }

    public function testDkimSubdomainHostnameIsDetected(): void
    {
        $errors = TxtRecordValueValidator::validate(
            'mail._domainkey',
            'k=rsa; p=AAAA'
        );
        // Missing v=DKIM1 — should produce an error, confirming detection worked
        $this->assertNotEmpty($errors);
    }

    // --- DMARC ---

    public function testValidDmarcPassesValidation(): void
    {
        $errors = TxtRecordValueValidator::validate(
            '_dmarc',
            'v=DMARC1; p=reject; rua=mailto:dmarc@example.com; pct=100'
        );
        $this->assertEmpty($errors);
    }

    public function testDmarcNonePolicyPassesValidation(): void
    {
        $errors = TxtRecordValueValidator::validate('_dmarc', 'v=DMARC1; p=none');
        $this->assertEmpty($errors);
    }

    public function testDmarcSubdomainHostnameIsDetected(): void
    {
        $errors = TxtRecordValueValidator::validate('_dmarc.subdomain', 'v=DMARC1; p=none');
        $this->assertEmpty($errors);
    }

    public function testDmarcWrongFirstTagFailsValidation(): void
    {
        $errors = TxtRecordValueValidator::validate('_dmarc', 'p=reject; v=DMARC1');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('v=DMARC1', $errors[0]);
    }

    public function testDmarcMissingPolicyTagFailsValidation(): void
    {
        $errors = TxtRecordValueValidator::validate('_dmarc', 'v=DMARC1');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('p=', $errors[0]);
    }

    public function testDmarcInvalidPolicyValueFailsValidation(): void
    {
        $errors = TxtRecordValueValidator::validate('_dmarc', 'v=DMARC1; p=discard');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('"none"', $errors[0]);
    }

    public function testDmarcInvalidSubdomainPolicyFailsValidation(): void
    {
        $errors = TxtRecordValueValidator::validate('_dmarc', 'v=DMARC1; p=none; sp=unknown');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('sp=', $errors[0]);
    }

    public function testDmarcInvalidAlignmentTagFailsValidation(): void
    {
        $errors = TxtRecordValueValidator::validate('_dmarc', 'v=DMARC1; p=none; adkim=x');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('adkim=', $errors[0]);
    }

    public function testDmarcInvalidPctFailsValidation(): void
    {
        $errors = TxtRecordValueValidator::validate('_dmarc', 'v=DMARC1; p=none; pct=150');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('pct=', $errors[0]);
    }

    public function testDmarcPctZeroPassesValidation(): void
    {
        $errors = TxtRecordValueValidator::validate('_dmarc', 'v=DMARC1; p=quarantine; pct=0');
        $this->assertEmpty($errors);
    }

    // --- Generic TXT ---

    public function testGenericTxtAtUnrecognizedHostnamePassesValidation(): void
    {
        $errors = TxtRecordValueValidator::validate('somehost', 'some arbitrary verification token value');
        $this->assertEmpty($errors);
    }

    public function testGenericTxtWithSpfLikeValueAtNonApexPassesValidation(): void
    {
        // v=spf1 at any hostname triggers SPF validation — standard behaviour
        $errors = TxtRecordValueValidator::validate('sub', 'v=spf1 -all');
        $this->assertEmpty($errors);
    }
}
