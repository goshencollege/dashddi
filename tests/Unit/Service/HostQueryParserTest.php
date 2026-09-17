<?php

namespace App\Tests\Unit\Service;

use App\Service\HostQueryParser;
use PHPUnit\Framework\TestCase;

class HostQueryParserTest extends TestCase
{
    private HostQueryParser $parser;

    protected function setUp(): void
    {
        $this->parser = new HostQueryParser();
    }

    public function testEmptyQueryReturnsNoGroups(): void
    {
        $this->assertSame([], $this->parser->parse(''));
        $this->assertSame([], $this->parser->parse('   '));
    }

    public function testPlainTextWithNoKnownFieldReturnsNoGroups(): void
    {
        $this->assertSame([], $this->parser->parse('just some text'));
    }

    public function testSingleFieldValueToken(): void
    {
        $this->assertSame([[['name', 'web01', false]]], $this->parser->parse('name:web01'));
    }

    public function testAndCombinesConditionsWithinAGroup(): void
    {
        $this->assertSame(
            [[['name', 'web01', false], ['building', '3', false]]],
            $this->parser->parse('name:web01 AND building:3'),
        );
    }

    public function testOrProducesSeparateGroups(): void
    {
        $this->assertSame(
            [[['name', 'web01', false]], [['name', 'web02', false]]],
            $this->parser->parse('name:web01 OR name:web02'),
        );
    }

    public function testNegationStripsBangAndSetsFlag(): void
    {
        $this->assertSame([[['tag', 'switch', true]]], $this->parser->parse('tag:!switch'));
    }

    public function testQuotedValueWithSpacesIsUnwrapped(): void
    {
        $this->assertSame([[['room', 'Server Room A', false]]], $this->parser->parse('room:"Server Room A"'));
    }

    public function testParenthesizedGroupingIsRespectedWhenSplittingOnOr(): void
    {
        $result = $this->parser->parse('(name:a AND building:1) OR name:b');
        $this->assertSame(
            [[['name', 'a', false], ['building', '1', false]], [['name', 'b', false]]],
            $result,
        );
    }

    public function testUnknownFieldTokenIsIgnored(): void
    {
        $this->assertSame([], $this->parser->parse('bogus:value'));
    }

    public function testMixOfKnownAndUnknownFieldsKeepsOnlyKnown(): void
    {
        $this->assertSame(
            [[['name', 'web01', false]]],
            $this->parser->parse('name:web01 AND bogus:value'),
        );
    }
}
