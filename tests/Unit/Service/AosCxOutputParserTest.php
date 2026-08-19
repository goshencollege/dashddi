<?php

namespace App\Tests\Unit\Service;

use App\Service\AosCxOutputParser;
use PHPUnit\Framework\TestCase;

class AosCxOutputParserTest extends TestCase
{
    /** Real `show interface brief` output from an Aruba 6300 (AOS-CX). The header
     *  pads "Enabled" and "Status" with only a single space between them while the
     *  data columns below stay several spaces apart — this is the case that broke
     *  the old header-column-position parser (it read "yes"/"no" as the status). */
    private const REAL_INTERFACE_BRIEF = <<<TXT
    ----------------------------------------------------------------------------------------------------
    Port           Native  Mode   Type           Enabled Status  Reason                  Speed   Description
                   VLAN                                                                  (Mb/s)
    ----------------------------------------------------------------------------------------------------
    1/1/1          44      access 1GbT           yes     down    Waiting for link        --      --
    1/1/3          44      trunk  1GbT           yes     up                              1000    --
    1/1/7          44      access 1GbT           no      down    Administratively down   --      --
    1/1/11         44      access 1GbT           yes     up                              100     --
    1/1/49         1       access --             yes     down    No XCVR installed       --      --
    1/1/50         --      routed 10G-LR         yes     up                              10000   uplink-to-gl
    loopback0      --      routed --             yes     up                              --      --
    vlan44         --      --     --             yes     up                              --      --
    lag1           1       trunk  --             yes     up      --                      10000   uplink-to-cc1

    cx-cc0#
    TXT;

    public function testParseInterfaceBriefReadsStatusNotEnabledColumn(): void
    {
        $result = AosCxOutputParser::parseInterfaceBrief(self::REAL_INTERFACE_BRIEF);

        $this->assertSame('down', $result['1/1/1']['status']);
        $this->assertNull($result['1/1/1']['speed']);

        $this->assertSame('up', $result['1/1/3']['status']);
        $this->assertSame('1000', $result['1/1/3']['speed']);
    }

    public function testParseInterfaceBriefHandlesAdministrativelyDownAndNoXcvrReasons(): void
    {
        $result = AosCxOutputParser::parseInterfaceBrief(self::REAL_INTERFACE_BRIEF);

        $this->assertSame('down', $result['1/1/7']['status']);
        $this->assertSame('down', $result['1/1/49']['status']);
    }

    public function testParseInterfaceBriefExtractsSpeedForUpPorts(): void
    {
        $result = AosCxOutputParser::parseInterfaceBrief(self::REAL_INTERFACE_BRIEF);

        $this->assertSame('100', $result['1/1/11']['speed']);
        $this->assertSame('10000', $result['1/1/50']['speed']);
    }

    public function testParseInterfaceBriefSkipsNonPhysicalInterfaces(): void
    {
        $result = AosCxOutputParser::parseInterfaceBrief(self::REAL_INTERFACE_BRIEF);

        $this->assertArrayNotHasKey('loopback0', $result);
        $this->assertArrayNotHasKey('vlan44', $result);
        $this->assertArrayNotHasKey('lag1', $result);
    }

    public function testParseInterfaceBriefReturnsEmptyForUnrecognisableInput(): void
    {
        $this->assertSame([], AosCxOutputParser::parseInterfaceBrief("nonsense\noutput\n"));
    }

    public function testParseMacAddressTableGroupsByPort(): void
    {
        $output = <<<TXT
        MAC age-time : 300 seconds

        MAC Address          VLAN    Type      Port
        -------------------- ------- --------- ---------
        00:11:22:33:44:55    10      dynamic   1/1/5
        aa:bb:cc:dd:ee:ff    10      dynamic   1/1/5
        11:22:33:44:55:66    20      dynamic   1/1/10
        switch1#
        TXT;

        $result = AosCxOutputParser::parseMacAddressTable($output);

        $this->assertCount(2, $result['1/1/5']);
        $this->assertSame('00:11:22:33:44:55', $result['1/1/5'][0]['mac']);
        $this->assertSame('10', $result['1/1/5'][0]['vlan']);
        $this->assertCount(1, $result['1/1/10']);
        $this->assertSame('11:22:33:44:55:66', $result['1/1/10'][0]['mac']);
    }

    public function testParseMacAddressTableSkipsMalformedRows(): void
    {
        $output = <<<TXT
        MAC Address          VLAN    Type      Port
        -------------------- ------- --------- ---------
        not-a-mac             10      dynamic   1/1/5
        switch1#
        TXT;

        $this->assertSame([], AosCxOutputParser::parseMacAddressTable($output));
    }

    public function testParseLldpNeighborInfoTableFormat(): void
    {
        $output = <<<TXT
        Local Port   Chassis ID           Port ID   System Name
        ------------ -------------------- --------- -----------------
        1/1/1        70:3a:0e:11:22:33    1/1/48    core-switch.example.com
        switch1#
        TXT;

        $result = AosCxOutputParser::parseLldpNeighborInfo($output);

        $this->assertSame('core-switch.example.com', $result['1/1/1']['neighborName']);
        $this->assertSame('1/1/48', $result['1/1/1']['neighborPort']);
    }

    public function testParseLldpNeighborInfoKeyValueFormat(): void
    {
        $output = <<<TXT
        Local Port : 1/1/1
        Chassis ID : 70:3a:0e:11:22:33
        Port ID    : 1/1/48
        System Name: core-switch.example.com
        --------------------------------------------------------------
        switch1#
        TXT;

        $result = AosCxOutputParser::parseLldpNeighborInfo($output);

        $this->assertSame('core-switch.example.com', $result['1/1/1']['neighborName']);
        $this->assertSame('1/1/48', $result['1/1/1']['neighborPort']);
    }

    public function testParseLldpNeighborInfoReturnsEmptyWithoutNeighbors(): void
    {
        $this->assertSame([], AosCxOutputParser::parseLldpNeighborInfo("No LLDP neighbors found.\nswitch1#"));
    }

    public function testParsePortAccessClientsCapturesPortAndTreatsClientNameAsMacWhenMacLike(): void
    {
        $output = <<<TXT
        Port     Client-Name          IPv4-Address    User-Role    VLAN    Flags
        -------- --------------------- --------------- ------------ ------- ---------
        1/1/5    aa:bb:cc:dd:ee:ff     10.0.0.5        employee     (a)10  1x|a|b|s
        1/1/6    some-device-name      10.0.0.6        guest        (a)20  ma|a|b|f
        switch1#
        TXT;

        $result = AosCxOutputParser::parsePortAccessClients($output);

        $this->assertCount(1, $result['1/1/5']);
        $this->assertSame('aa:bb:cc:dd:ee:ff', $result['1/1/5'][0]['mac']);
        $this->assertSame('10', $result['1/1/5'][0]['vlan']);
        $this->assertSame('802.1X', $result['1/1/5'][0]['authMethod']);
        $this->assertSame('Success', $result['1/1/5'][0]['status']);

        $this->assertNull($result['1/1/6'][0]['mac']);
        $this->assertSame('MAC-Auth', $result['1/1/6'][0]['authMethod']);
        $this->assertSame('Failed', $result['1/1/6'][0]['status']);
    }

    public function testParsePortAccessClientsReturnsEmptyWithoutRecognisedHeader(): void
    {
        $this->assertSame([], AosCxOutputParser::parsePortAccessClients("nothing here\n"));
    }
}
