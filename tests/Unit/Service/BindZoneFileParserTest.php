<?php

namespace App\Tests\Unit\Service;

use App\Service\BindZoneFileParser;
use PHPUnit\Framework\TestCase;

class BindZoneFileParserTest extends TestCase
{
    private BindZoneFileParser $parser;

    protected function setUp(): void
    {
        $this->parser = new BindZoneFileParser();
    }

    private function findRecord(array $records, string $name, string $type): ?array
    {
        foreach ($records as $r) {
            if ($r['name'] === $name && $r['type'] === $type) {
                return $r;
            }
        }
        return null;
    }

    public function testParsesARecord(): void
    {
        $zone = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
www IN A 192.168.1.10
ZONE;

        $result = $this->parser->parse($zone, 'example.com');
        $record = $this->findRecord($result['records'], 'www', 'A');

        $this->assertNotNull($record);
        $this->assertSame('192.168.1.10', $record['value']);
        $this->assertSame(3600, $record['ttl']);
    }

    public function testParsesAAAARecord(): void
    {
        $zone = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
www IN AAAA 2001:db8::1
ZONE;

        $result = $this->parser->parse($zone, 'example.com');
        $record = $this->findRecord($result['records'], 'www', 'AAAA');

        $this->assertNotNull($record);
        $this->assertSame('2001:db8::1', $record['value']);
    }

    public function testParsesCnameRecord(): void
    {
        $zone = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
alias IN CNAME www.example.com.
ZONE;

        $result = $this->parser->parse($zone, 'example.com');
        $record = $this->findRecord($result['records'], 'alias', 'CNAME');

        $this->assertNotNull($record);
        // Trailing dot stripped by normalizeValue
        $this->assertSame('www.example.com', $record['value']);
    }

    public function testParsesMxRecord(): void
    {
        $zone = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
@ IN MX 10 mail.example.com.
ZONE;

        $result = $this->parser->parse($zone, 'example.com');
        $record = $this->findRecord($result['records'], '@', 'MX');

        $this->assertNotNull($record);
        $this->assertStringContainsString('mail.example.com', $record['value']);
    }

    public function testParsesTxtRecord(): void
    {
        $zone = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
@ IN TXT "v=spf1 include:example.com ~all"
ZONE;

        $result = $this->parser->parse($zone, 'example.com');
        $record = $this->findRecord($result['records'], '@', 'TXT');

        $this->assertNotNull($record);
        $this->assertSame('v=spf1 include:example.com ~all', $record['value']);
    }

    public function testHandlesOriginDirective(): void
    {
        $zone = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
www IN A 10.0.0.1
ZONE;

        $result = $this->parser->parse($zone);
        $this->assertSame('example.com', $result['origin']);
    }

    public function testHandlesTtlDirective(): void
    {
        $zone = <<<ZONE
\$ORIGIN example.com.
\$TTL 7200
host IN A 10.0.0.2
ZONE;

        $result = $this->parser->parse($zone, 'example.com');
        $record = $this->findRecord($result['records'], 'host', 'A');

        $this->assertNotNull($record);
        $this->assertSame(7200, $record['ttl']);
    }

    public function testIgnoresUnsupportedRecordTypes(): void
    {
        $zone = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
@ IN SOA ns1.example.com. hostmaster.example.com. 2024010101 3600 900 604800 3600
www IN A 10.0.0.1
ZONE;

        $result = $this->parser->parse($zone, 'example.com');
        $soaRecord = $this->findRecord($result['records'], '@', 'SOA');
        $aRecord = $this->findRecord($result['records'], 'www', 'A');

        // SOA is filtered from results (not in SUPPORTED_TYPES), A record is included
        $this->assertNull($soaRecord);
        $this->assertNotNull($aRecord);
    }

    public function testStripsComments(): void
    {
        $zone = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
; This is a comment
www IN A 10.0.0.5 ; inline comment
ZONE;

        $result = $this->parser->parse($zone, 'example.com');
        $record = $this->findRecord($result['records'], 'www', 'A');

        $this->assertNotNull($record);
        $this->assertSame('10.0.0.5', $record['value']);
    }

    public function testHandlesMultiLineRecordWithParentheses(): void
    {
        $zone = <<<'ZONE'
$ORIGIN example.com.
$TTL 3600
@ IN SOA ns1.example.com. hostmaster.example.com. (
    2024010101 ; serial
    3600       ; refresh
    900        ; retry
    604800     ; expire
    3600       ; minimum TTL
)
www IN A 10.0.0.1
ZONE;

        $result = $this->parser->parse($zone, 'example.com');
        // SOA skipped, but A record should parse correctly after the multi-line block
        $record = $this->findRecord($result['records'], 'www', 'A');
        $this->assertNotNull($record);
    }

    public function testInheritedNameFromIndentedLine(): void
    {
        $zone = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
www IN A 10.0.0.1
    IN AAAA 2001:db8::1
ZONE;

        $result = $this->parser->parse($zone, 'example.com');
        $aRecord = $this->findRecord($result['records'], 'www', 'A');
        $aaaaRecord = $this->findRecord($result['records'], 'www', 'AAAA');

        $this->assertNotNull($aRecord);
        $this->assertNotNull($aaaaRecord);
    }

    public function testNormalizesLabelRelativeToOrigin(): void
    {
        $zone = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
www.example.com. IN A 10.0.0.1
ZONE;

        $result = $this->parser->parse($zone, 'example.com');
        $record = $this->findRecord($result['records'], 'www', 'A');

        $this->assertNotNull($record, 'FQDN with origin suffix should normalize to relative label');
    }

    public function testParsesNsRecord(): void
    {
        $zone = <<<ZONE
\$ORIGIN example.com.
\$TTL 3600
@ IN NS ns1.example.com.
ZONE;

        $result = $this->parser->parse($zone, 'example.com');
        $record = $this->findRecord($result['records'], '@', 'NS');

        $this->assertNotNull($record);
        // Trailing dot stripped
        $this->assertSame('ns1.example.com', $record['value']);
    }

    public function testTtlWithUnitSuffix(): void
    {
        $zone = <<<ZONE
\$ORIGIN example.com.
\$TTL 1h
host IN A 10.0.0.3
ZONE;

        $result = $this->parser->parse($zone, 'example.com');
        $record = $this->findRecord($result['records'], 'host', 'A');

        $this->assertNotNull($record);
        $this->assertSame(3600, $record['ttl']);
    }
}
