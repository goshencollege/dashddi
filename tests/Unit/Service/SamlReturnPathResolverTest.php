<?php

namespace App\Tests\Unit\Service;

use App\Service\SamlReturnPathResolver;
use PHPUnit\Framework\TestCase;

class SamlReturnPathResolverTest extends TestCase
{
    private SamlReturnPathResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new SamlReturnPathResolver();
    }

    public function testNullIsRejected(): void
    {
        $this->assertNull($this->resolver->sanitize(null));
    }

    public function testEmptyStringIsRejected(): void
    {
        $this->assertNull($this->resolver->sanitize(''));
    }

    public function testRelativePathIsAccepted(): void
    {
        $this->assertSame('/domains/1', $this->resolver->sanitize('/domains/1'));
    }

    public function testRelativePathWithQueryStringIsAccepted(): void
    {
        $this->assertSame('/hosts?page=2', $this->resolver->sanitize('/hosts?page=2'));
    }

    public function testAbsoluteUrlIsRejected(): void
    {
        $this->assertNull($this->resolver->sanitize('https://evil.example/phish'));
    }

    public function testProtocolRelativeUrlIsRejected(): void
    {
        $this->assertNull($this->resolver->sanitize('//evil.example/phish'));
    }

    public function testBackslashSchemeTrickIsRejected(): void
    {
        $this->assertNull($this->resolver->sanitize('/\\evil.example/phish'));
    }

    public function testPathNotStartingWithSlashIsRejected(): void
    {
        $this->assertNull($this->resolver->sanitize('domains/1'));
    }
}
