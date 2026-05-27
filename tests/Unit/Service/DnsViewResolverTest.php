<?php

namespace App\Tests\Unit\Service;

use App\Entity\DnsView;
use App\Entity\Domain;
use App\Entity\Subnet;
use App\Service\DnsViewResolver;
use PHPUnit\Framework\TestCase;

class DnsViewResolverTest extends TestCase
{
    private DnsViewResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new DnsViewResolver();
    }

    private function makeView(int $id): DnsView
    {
        $view = new DnsView();
        $prop = new \ReflectionProperty(DnsView::class, 'id');
        $prop->setValue($view, $id);
        return $view;
    }

    public function testNullDomainReturnsEmpty(): void
    {
        $this->assertSame([], $this->resolver->availableViewsFor(null, null));
    }

    public function testNullSubnetReturnsDomainViews(): void
    {
        $domain = new Domain();
        $view = $this->makeView(1);
        $domain->addView($view);

        $result = $this->resolver->availableViewsFor($domain, null);
        $this->assertCount(1, $result);
        $this->assertSame($view, $result[0]);
    }

    public function testSubnetWithNoViewsReturnsDomainViews(): void
    {
        $domain = new Domain();
        $domain->addView($this->makeView(1));
        $subnet = new Subnet();

        $result = $this->resolver->availableViewsFor($domain, $subnet);
        $this->assertCount(1, $result);
    }

    public function testReturnsIntersectionOfDomainAndSubnetViews(): void
    {
        $v1 = $this->makeView(1);
        $v2 = $this->makeView(2);
        $v3 = $this->makeView(3);

        $domain = new Domain();
        $domain->addView($v1);
        $domain->addView($v2);

        $subnet = new Subnet();
        $subnet->addView($v2);
        $subnet->addView($v3);

        $result = $this->resolver->availableViewsFor($domain, $subnet);
        $this->assertCount(1, $result);
        $this->assertSame($v2, $result[0]);
    }

    public function testNoIntersectionReturnsEmpty(): void
    {
        $domain = new Domain();
        $domain->addView($this->makeView(1));

        $subnet = new Subnet();
        $subnet->addView($this->makeView(2));

        $result = $this->resolver->availableViewsFor($domain, $subnet);
        $this->assertSame([], $result);
    }

    public function testMultipleSharedViewsReturned(): void
    {
        $v1 = $this->makeView(1);
        $v2 = $this->makeView(2);

        $domain = new Domain();
        $domain->addView($v1);
        $domain->addView($v2);

        $subnet = new Subnet();
        $subnet->addView($v1);
        $subnet->addView($v2);

        $result = $this->resolver->availableViewsFor($domain, $subnet);
        $this->assertCount(2, $result);
    }

    public function testIsDomainUsableReturnsFalseWhenDomainHasNoViews(): void
    {
        $domain = new Domain();
        $this->assertFalse($this->resolver->isDomainUsable($domain, null));
    }

    public function testIsDomainUsableReturnsTrueWhenNoSubnetConstraint(): void
    {
        $domain = new Domain();
        $domain->addView($this->makeView(1));
        $this->assertTrue($this->resolver->isDomainUsable($domain, null));
    }

    public function testIsDomainUsableReturnsTrueWhenSubnetHasNoViews(): void
    {
        $domain = new Domain();
        $domain->addView($this->makeView(1));

        $this->assertTrue($this->resolver->isDomainUsable($domain, new Subnet()));
    }

    public function testIsDomainUsableReturnsFalseWhenNoSharedViews(): void
    {
        $domain = new Domain();
        $domain->addView($this->makeView(1));

        $subnet = new Subnet();
        $subnet->addView($this->makeView(2));

        $this->assertFalse($this->resolver->isDomainUsable($domain, $subnet));
    }

    public function testUnusableDomainReasonNoDomainViews(): void
    {
        $domain = new Domain();
        $this->assertSame(
            'Domain has no views configured',
            $this->resolver->unusableDomainReason($domain, null)
        );
    }

    public function testUnusableDomainReasonNoCommonViews(): void
    {
        $domain = new Domain();
        $domain->addView($this->makeView(1));

        $subnet = new Subnet();
        $subnet->addView($this->makeView(2));

        $this->assertSame(
            'No views in common with this subnet',
            $this->resolver->unusableDomainReason($domain, $subnet)
        );
    }

    public function testUnusableDomainReasonEmptyWhenUsable(): void
    {
        $v = $this->makeView(1);
        $domain = new Domain();
        $domain->addView($v);

        $subnet = new Subnet();
        $subnet->addView($v);

        $this->assertSame('', $this->resolver->unusableDomainReason($domain, $subnet));
    }
}
