<?php

namespace App\Tests\Unit\Service;

use App\Service\UserGuideSearchService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class UserGuideSearchServiceTest extends TestCase
{
    private function makeService(array $templates, array $pages): UserGuideSearchService
    {
        $twig = new Environment(new ArrayLoader($templates));

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturnCallback(
            fn (string $key, callable $callback) => $callback($this->createStub(ItemInterface::class))
        );

        return new UserGuideSearchService($twig, $cache, $pages);
    }

    private function defaultPages(): array
    {
        return [
            'hosts.html.twig' => ['route' => 'user_guide_hosts', 'title' => 'Hosts & Interfaces', 'icon' => 'bi-pc-display'],
            'dns.html.twig' => ['route' => 'user_guide_dns', 'title' => 'DNS Management', 'icon' => 'bi-globe'],
        ];
    }

    public function testSearchMatchesSectionTitle(): void
    {
        $service = $this->makeService([
            'hosts.html.twig' => '{% block content %}<div class="card" id="search"><div class="card-header">Searching for Hosts</div><div class="card-body">Use wildcards to filter the host list.</div></div>{% endblock %}',
            'dns.html.twig' => '{% block content %}<div class="card" id="records"><div class="card-header">DNS Records</div><div class="card-body">Create A, AAAA, CNAME, and TXT records.</div></div>{% endblock %}',
        ], $this->defaultPages());

        $results = $service->search('searching');

        $this->assertCount(1, $results);
        $this->assertSame('user_guide_hosts', $results[0]['page']);
        $this->assertSame('search', $results[0]['sectionId']);
        $this->assertSame('Searching for Hosts', $results[0]['sectionTitle']);
    }

    public function testSearchMatchesSectionBodyText(): void
    {
        $service = $this->makeService([
            'hosts.html.twig' => '{% block content %}<div class="card" id="overview"><div class="card-header">Overview</div><div class="card-body">A host has one or more network interfaces.</div></div>{% endblock %}',
            'dns.html.twig' => '{% block content %}<div class="card" id="records"><div class="card-header">DNS Records</div><div class="card-body">Create A, AAAA, CNAME, and TXT records.</div></div>{% endblock %}',
        ], $this->defaultPages());

        $results = $service->search('network interfaces');

        $this->assertCount(1, $results);
        $this->assertSame('overview', $results[0]['sectionId']);
        $this->assertStringContainsString('network interfaces', $results[0]['snippet']);
    }

    public function testSearchRanksTitleMatchesBeforeBodyMatches(): void
    {
        $service = $this->makeService([
            'hosts.html.twig' => '{% block content %}<div class="card" id="dns-names"><div class="card-header">DNS Names on an Interface</div><div class="card-body">Nothing relevant here.</div></div>{% endblock %}',
            'dns.html.twig' => '{% block content %}<div class="card" id="records"><div class="card-header">Records</div><div class="card-body">DNS records live inside a domain (zone).</div></div>{% endblock %}',
        ], $this->defaultPages());

        $results = $service->search('dns');

        $this->assertCount(2, $results);
        $this->assertSame('dns-names', $results[0]['sectionId']);
        $this->assertSame('records', $results[1]['sectionId']);
    }

    public function testSearchReturnsEmptyArrayForNoMatches(): void
    {
        $service = $this->makeService([
            'hosts.html.twig' => '{% block content %}<div class="card" id="overview"><div class="card-header">Overview</div><div class="card-body">Hosts and interfaces.</div></div>{% endblock %}',
        ], ['hosts.html.twig' => $this->defaultPages()['hosts.html.twig']]);

        $this->assertSame([], $service->search('zzzznotfound'));
    }

    public function testSearchReturnsEmptyArrayForShortQuery(): void
    {
        $service = $this->makeService([
            'hosts.html.twig' => '{% block content %}<div class="card" id="overview"><div class="card-header">Overview</div><div class="card-body">Hosts and interfaces.</div></div>{% endblock %}',
        ], ['hosts.html.twig' => $this->defaultPages()['hosts.html.twig']]);

        $this->assertSame([], $service->search('h'));
        $this->assertSame([], $service->search('  '));
    }

    public function testSearchIsCaseInsensitive(): void
    {
        $service = $this->makeService([
            'hosts.html.twig' => '{% block content %}<div class="card" id="overview"><div class="card-header">Overview</div><div class="card-body">Hosts and Interfaces.</div></div>{% endblock %}',
        ], ['hosts.html.twig' => $this->defaultPages()['hosts.html.twig']]);

        $this->assertCount(1, $service->search('INTERFACES'));
    }

    public function testSearchSkipsCardsWithoutBodyText(): void
    {
        $service = $this->makeService([
            'hosts.html.twig' => '{% block content %}<div class="card" id="empty"><div class="card-header">Empty Section</div></div>{% endblock %}',
        ], ['hosts.html.twig' => $this->defaultPages()['hosts.html.twig']]);

        $this->assertSame([], $service->search('empty'));
    }

    public function testSearchRespectsLimit(): void
    {
        $cards = '';
        for ($i = 1; $i <= 5; $i++) {
            $cards .= '<div class="card" id="s' . $i . '"><div class="card-header">Section ' . $i . '</div><div class="card-body">Matching text here.</div></div>';
        }

        $service = $this->makeService([
            'hosts.html.twig' => '{% block content %}' . $cards . '{% endblock %}',
        ], ['hosts.html.twig' => $this->defaultPages()['hosts.html.twig']]);

        $this->assertCount(2, $service->search('matching', 2));
    }

    public function testSearchSnippetTruncatesLongBodyText(): void
    {
        $longText = str_repeat('word ', 100) . 'needle' . str_repeat(' word', 100);

        $service = $this->makeService([
            'hosts.html.twig' => '{% block content %}<div class="card" id="long"><div class="card-header">Long Section</div><div class="card-body">' . $longText . '</div></div>{% endblock %}',
        ], ['hosts.html.twig' => $this->defaultPages()['hosts.html.twig']]);

        $results = $service->search('needle');

        $this->assertCount(1, $results);
        $this->assertStringStartsWith('…', $results[0]['snippet']);
        $this->assertStringEndsWith('…', $results[0]['snippet']);
        $this->assertStringContainsString('needle', $results[0]['snippet']);
    }
}
