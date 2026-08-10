<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Host;
use PHPUnit\Framework\TestCase;

class HostTest extends TestCase
{
    public function testSetDuidNormalizesPlainHexToColonPairs(): void
    {
        $host = new Host();
        $host->setDuid('00020000ab11cc5702f3da97b768');

        $this->assertSame('00:02:00:00:ab:11:cc:57:02:f3:da:97:b7:68', $host->getDuid());
    }

    public function testSetDuidBlankBecomesNull(): void
    {
        $host = new Host();
        $host->setDuid('   ');

        $this->assertNull($host->getDuid());
    }

    public function testSetDuidParsesNetworkctlEnVendorLabel(): void
    {
        $host = new Host();
        $host->setDuid('DUID-EN/Vendor:0000ab11cc5702f3da97b768');

        $this->assertSame('00:02:00:00:ab:11:cc:57:02:f3:da:97:b7:68', $host->getDuid());
    }

    public function testSetDuidParsesFullNetworkctlLineWithLeadingText(): void
    {
        $host = new Host();
        $host->setDuid('DHCP6 Client DUID: DUID-EN/Vendor:0000ab11cc5702f3da97b768');

        $this->assertSame('00:02:00:00:ab:11:cc:57:02:f3:da:97:b7:68', $host->getDuid());
    }

    public function testSetDuidParsesPlainEnLabelWithoutVendorSuffix(): void
    {
        $host = new Host();
        $host->setDuid('DUID-EN:0000ab11cc5702f3da97b768');

        $this->assertSame('00:02:00:00:ab:11:cc:57:02:f3:da:97:b7:68', $host->getDuid());
    }

    public function testSetDuidParsesLltLabelWithoutMisreadingAsLl(): void
    {
        $host = new Host();
        $host->setDuid('DUID-LLT:00011234567890abcdef');

        $this->assertSame('00:01:00:01:12:34:56:78:90:ab:cd:ef', $host->getDuid());
    }

    public function testSetDuidParsesLlLabel(): void
    {
        $host = new Host();
        $host->setDuid('DUID-LL:aabbccddeeff');

        $this->assertSame('00:03:aa:bb:cc:dd:ee:ff', $host->getDuid());
    }

    public function testSetDuidParsesUuidLabel(): void
    {
        $host = new Host();
        $host->setDuid('DUID-UUID:0102030405060708090a0b0c0d0e0f10');

        $this->assertSame('00:04:01:02:03:04:05:06:07:08:09:0a:0b:0c:0d:0e:0f:10', $host->getDuid());
    }

    public function testSetDuidLabelMatchIsCaseInsensitive(): void
    {
        $host = new Host();
        $host->setDuid('duid-en/vendor:0000ab11cc5702f3da97b768');

        $this->assertSame('00:02:00:00:ab:11:cc:57:02:f3:da:97:b7:68', $host->getDuid());
    }

    public function testGetDuidDisplayReturnsNullWhenDuidIsUnset(): void
    {
        $host = new Host();

        $this->assertNull($host->getDuidDisplay());
    }

    public function testGetDuidDisplayFallsBackToRawHexForUnrecognizedTypeCode(): void
    {
        $host = new Host();
        $host->setDuid('ffffab11cc5702f3da97b768');

        $this->assertSame('ff:ff:ab:11:cc:57:02:f3:da:97:b7:68', $host->getDuidDisplay());
    }

    public function testGetDuidDisplayReconstructsLltLabel(): void
    {
        $host = new Host();
        $host->setDuid('DUID-LLT:00011234567890abcdef');

        $this->assertSame('DUID-LLT:00011234567890abcdef', $host->getDuidDisplay());
    }

    public function testGetDuidDisplayReconstructsEnVendorLabel(): void
    {
        $host = new Host();
        $host->setDuid('DUID-EN/Vendor:0000ab11cc5702f3da97b768');

        $this->assertSame('DUID-EN/Vendor:0000ab11cc5702f3da97b768', $host->getDuidDisplay());
    }

    public function testGetDuidDisplayReconstructsLlLabel(): void
    {
        $host = new Host();
        $host->setDuid('DUID-LL:aabbccddeeff');

        $this->assertSame('DUID-LL:aabbccddeeff', $host->getDuidDisplay());
    }

    public function testGetDuidDisplayReconstructsUuidLabel(): void
    {
        $host = new Host();
        $host->setDuid('DUID-UUID:0102030405060708090a0b0c0d0e0f10');

        $this->assertSame('DUID-UUID:0102030405060708090a0b0c0d0e0f10', $host->getDuidDisplay());
    }

    public function testGetDuidDisplayReconstructsEnVendorLabelFromPlainHexInput(): void
    {
        $host = new Host();
        $host->setDuid('00020000ab11cc5702f3da97b768');

        $this->assertSame('DUID-EN/Vendor:0000ab11cc5702f3da97b768', $host->getDuidDisplay());
    }
}
