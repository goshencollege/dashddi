<?php

namespace App\Tests\Unit\Service;

use App\Service\ReservedTagPrefixService;
use PHPUnit\Framework\TestCase;

class ReservedTagPrefixServiceTest extends TestCase
{
    public function testMatchingPrefixReturnsPrefix(): void
    {
        $service = new ReservedTagPrefixService(['snipe-', 'cp-']);
        $this->assertSame('snipe-', $service->matchingPrefix('snipe-IT-asset'));
    }

    public function testMatchingPrefixIsCaseInsensitive(): void
    {
        $service = new ReservedTagPrefixService(['Snipe-']);
        $this->assertSame('Snipe-', $service->matchingPrefix('SNIPE-asset'));
    }

    public function testMatchingPrefixReturnsNullWhenNoMatch(): void
    {
        $service = new ReservedTagPrefixService(['snipe-', 'cp-']);
        $this->assertNull($service->matchingPrefix('custom-tag'));
    }

    public function testMatchingPrefixWithEmptyPrefixList(): void
    {
        $service = new ReservedTagPrefixService([]);
        $this->assertNull($service->matchingPrefix('anything'));
    }

    public function testMatchingPrefixReturnsFirstMatch(): void
    {
        $service = new ReservedTagPrefixService(['snipe-', 'snipe-it-']);
        $this->assertSame('snipe-', $service->matchingPrefix('snipe-it-laptop'));
    }

    public function testPartialSubstringDoesNotMatch(): void
    {
        $service = new ReservedTagPrefixService(['snipe-']);
        $this->assertNull($service->matchingPrefix('not-snipe-tag'));
    }

    public function testGetPrefixesReturnsConfiguredArray(): void
    {
        $prefixes = ['snipe-', 'cp-', 'dhcp-'];
        $service = new ReservedTagPrefixService($prefixes);
        $this->assertSame($prefixes, $service->getPrefixes());
    }

    public function testExactPrefixMatchWithNoTrailingContent(): void
    {
        $service = new ReservedTagPrefixService(['snipe-']);
        $this->assertSame('snipe-', $service->matchingPrefix('snipe-'));
    }
}
