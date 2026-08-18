<?php

namespace App\Tests\Unit\Service;

use App\Service\OuiLookupService;
use PHPUnit\Framework\TestCase;

class OuiLookupServiceTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        $this->databasePath = tempnam(sys_get_temp_dir(), 'oui-test');
        file_put_contents($this->databasePath, "<?php\nreturn ['001A2B' => 'Acme Networks, Inc.'];\n");
    }

    protected function tearDown(): void
    {
        unlink($this->databasePath);
    }

    public function testLookupReturnsVendorForKnownOuiColonSeparated(): void
    {
        $service = new OuiLookupService($this->databasePath);
        $this->assertSame('Acme Networks, Inc.', $service->lookup('00:1a:2b:cc:dd:ee'));
    }

    public function testLookupIsCaseInsensitive(): void
    {
        $service = new OuiLookupService($this->databasePath);
        $this->assertSame('Acme Networks, Inc.', $service->lookup('00:1A:2B:CC:DD:EE'));
    }

    public function testLookupHandlesDashSeparatedMac(): void
    {
        $service = new OuiLookupService($this->databasePath);
        $this->assertSame('Acme Networks, Inc.', $service->lookup('00-1A-2B-CC-DD-EE'));
    }

    public function testLookupReturnsNullForUnknownOui(): void
    {
        $service = new OuiLookupService($this->databasePath);
        $this->assertNull($service->lookup('10:20:30:dd:ee:ff'));
    }

    public function testLookupReturnsNullForMalformedInput(): void
    {
        $service = new OuiLookupService($this->databasePath);
        $this->assertNull($service->lookup('not-a-mac'));
    }

    public function testLookupReturnsNullWhenDatabaseFileMissing(): void
    {
        $service = new OuiLookupService('/nonexistent/path/oui.php');
        $this->assertNull($service->lookup('00:1a:2b:cc:dd:ee'));
    }

    public function testLookupDetectsLocallyAdministeredAddress(): void
    {
        $service = new OuiLookupService($this->databasePath);
        $this->assertSame('Locally administered (randomized)', $service->lookup('02:1a:2b:cc:dd:ee'));
    }

    public function testLookupDoesNotFlagGloballyUniqueAddressAsLocal(): void
    {
        $service = new OuiLookupService($this->databasePath);
        $this->assertSame('Acme Networks, Inc.', $service->lookup('001a2bccddee'));
    }

    public function testLookupReturnsNullForAllZeroPlaceholderMac(): void
    {
        $service = new OuiLookupService($this->databasePath);
        $this->assertNull($service->lookup('00:00:00:00:00:00'));
    }
}
