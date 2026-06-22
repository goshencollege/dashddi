<?php

namespace App\Tests\Unit\Service;

use App\Service\HostCsvParser;
use PHPUnit\Framework\TestCase;

class HostCsvParserTest extends TestCase
{
    private HostCsvParser $parser;

    protected function setUp(): void
    {
        $this->parser = new HostCsvParser();
    }

    private function makeCsv(array $rows, array $headers = null): string
    {
        $headers ??= HostCsvParser::ALL_HEADERS;
        $lines   = [implode(',', $headers)];
        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }
        return implode("\n", $lines) . "\n";
    }

    public function testValidCsvWithFullDataParsesCorrectly(): void
    {
        $csv = $this->makeCsv([[
            'myhost', 'Main Building', '101', 'Some notes', 'tag1;tag2',
            'aa:bb:cc:dd:ee:ff', 'eth0', '192.168.1.0/24', '192.168.1.10', '', 'iface note',
        ]]);

        $result = $this->parser->parse($csv);

        $this->assertEmpty($result['errors']);
        $this->assertCount(1, $result['entries']);

        $entry = $result['entries'][0];
        $this->assertSame('myhost', $entry['hostname']);
        $this->assertSame('Main Building', $entry['building_name']);
        $this->assertSame('101', $entry['room']);
        $this->assertSame('Some notes', $entry['notes']);
        $this->assertSame(['tag1', 'tag2'], $entry['tags']);
        $this->assertCount(1, $entry['interfaces']);

        $iface = $entry['interfaces'][0];
        $this->assertSame('aa:bb:cc:dd:ee:ff', $iface['mac']);
        $this->assertSame('eth0', $iface['name']);
        $this->assertSame('192.168.1.0/24', $iface['subnet_cidr']);
        $this->assertSame('192.168.1.10', $iface['ip_address']);
        $this->assertNull($iface['ipv6_address']);
        $this->assertSame('iface note', $iface['notes']);
    }

    public function testMissingHostnameColumnReturnsError(): void
    {
        $csv = "mac_address\naa:bb:cc:dd:ee:ff\n";

        $result = $this->parser->parse($csv);

        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('hostname', $result['errors'][0]);
        $this->assertEmpty($result['entries']);
    }

    public function testMissingMacAddressColumnReturnsError(): void
    {
        $csv = "hostname\nmyhost\n";

        $result = $this->parser->parse($csv);

        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('mac_address', $result['errors'][0]);
        $this->assertEmpty($result['entries']);
    }

    public function testRowsWithSameHostnameGroupedIntoOneEntry(): void
    {
        $csv = $this->makeCsv([
            ['myhost', '', '', '', '', 'aa:bb:cc:dd:ee:01', '', '', '', '', ''],
            ['myhost', '', '', '', '', 'aa:bb:cc:dd:ee:02', '', '', '', '', ''],
        ]);

        $result = $this->parser->parse($csv);

        $this->assertEmpty($result['errors']);
        $this->assertCount(1, $result['entries']);
        $this->assertCount(2, $result['entries'][0]['interfaces']);
        $this->assertSame('aa:bb:cc:dd:ee:01', $result['entries'][0]['interfaces'][0]['mac']);
        $this->assertSame('aa:bb:cc:dd:ee:02', $result['entries'][0]['interfaces'][1]['mac']);
    }

    public function testBlankMacAddressRejectedWithLineNumber(): void
    {
        $csv = $this->makeCsv([
            ['myhost', '', '', '', '', '', '', '', '', '', ''],
        ]);

        $result = $this->parser->parse($csv);

        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Row 2', $result['errors'][0]);
        $this->assertStringContainsString('mac_address', $result['errors'][0]);
    }

    public function testInvalidMacAddressRejectedWithLineNumber(): void
    {
        $csv = $this->makeCsv([
            ['myhost', '', '', '', '', 'not-a-mac', '', '', '', '', ''],
        ]);

        $result = $this->parser->parse($csv);

        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Row 2', $result['errors'][0]);
        $this->assertStringContainsString('not-a-mac', $result['errors'][0]);
    }

    public function testMacNormalizationDashSeparators(): void
    {
        $csv = $this->makeCsv([
            ['myhost', '', '', '', '', 'AA-BB-CC-DD-EE-FF', '', '', '', '', ''],
        ]);

        $result = $this->parser->parse($csv);

        $this->assertEmpty($result['errors']);
        $this->assertSame('aa:bb:cc:dd:ee:ff', $result['entries'][0]['interfaces'][0]['mac']);
    }

    public function testMacNormalizationNoSeparators(): void
    {
        $csv = $this->makeCsv([
            ['myhost', '', '', '', '', 'aabbccddeeff', '', '', '', '', ''],
        ]);

        $result = $this->parser->parse($csv);

        $this->assertEmpty($result['errors']);
        $this->assertSame('aa:bb:cc:dd:ee:ff', $result['entries'][0]['interfaces'][0]['mac']);
    }

    public function testMacNormalizationMixedCase(): void
    {
        $csv = $this->makeCsv([
            ['myhost', '', '', '', '', 'Aa:Bb:Cc:Dd:Ee:Ff', '', '', '', '', ''],
        ]);

        $result = $this->parser->parse($csv);

        $this->assertEmpty($result['errors']);
        $this->assertSame('aa:bb:cc:dd:ee:ff', $result['entries'][0]['interfaces'][0]['mac']);
    }

    public function testEmptyFileReturnsError(): void
    {
        $result = $this->parser->parse('');

        $this->assertNotEmpty($result['errors']);
        $this->assertEmpty($result['entries']);
    }

    public function testWhitespaceOnlyFileReturnsError(): void
    {
        $result = $this->parser->parse("   \n   \n");

        $this->assertNotEmpty($result['errors']);
        $this->assertEmpty($result['entries']);
    }

    public function testHeadersAreCaseInsensitive(): void
    {
        $csv = "HOSTNAME,MAC_ADDRESS\nmyhost,aa:bb:cc:dd:ee:ff\n";

        $result = $this->parser->parse($csv);

        $this->assertEmpty($result['errors']);
        $this->assertCount(1, $result['entries']);
    }

    public function testHostLevelDataTakenFromFirstRow(): void
    {
        $csv = $this->makeCsv([
            ['myhost', 'BuildingA', '100', 'first notes', 'tagA', 'aa:bb:cc:dd:ee:01', '', '', '', '', ''],
            ['myhost', 'BuildingB', '200', 'second notes', 'tagB', 'aa:bb:cc:dd:ee:02', '', '', '', '', ''],
        ]);

        $result = $this->parser->parse($csv);

        $entry = $result['entries'][0];
        $this->assertSame('BuildingA', $entry['building_name']);
        $this->assertSame('100', $entry['room']);
        $this->assertSame('first notes', $entry['notes']);
        $this->assertSame(['tagA'], $entry['tags']);
    }

    public function testRowWithMissingHostnameFieldIsSkippedWithError(): void
    {
        $csv = $this->makeCsv([
            ['', '', '', '', '', 'aa:bb:cc:dd:ee:ff', '', '', '', '', ''],
        ]);

        $result = $this->parser->parse($csv);

        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('hostname', $result['errors'][0]);
        $this->assertEmpty($result['entries']);
    }

    public function testSemicolonSeparatedTagsParsedCorrectly(): void
    {
        $csv = $this->makeCsv([
            ['myhost', '', '', '', 'alpha;beta;gamma', 'aa:bb:cc:dd:ee:ff', '', '', '', '', ''],
        ]);

        $result = $this->parser->parse($csv);

        $this->assertSame(['alpha', 'beta', 'gamma'], $result['entries'][0]['tags']);
    }
}
