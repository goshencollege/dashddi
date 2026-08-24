<?php

namespace App\Tests\Functional\Controller;

use App\Entity\DnssecPolicy;
use App\Entity\DnsView;
use App\Entity\Domain;
use App\Entity\DomainRecord;
use App\Entity\UserPreference;
use App\Enum\RecordType;
use App\Tests\Functional\AppWebTestCase;

class DomainControllerTest extends AppWebTestCase
{
    private function makeView(string $name = 'test-view'): DnsView
    {
        $view = (new DnsView())->setName($name);
        $this->em->persist($view);
        $this->em->flush();
        return $view;
    }

    public function testIndexLoads(): void
    {
        $this->client->request('GET', '/domains');
        $this->assertResponseIsSuccessful();
    }

    public function testNewFormLoads(): void
    {
        $this->client->request('GET', '/domains/new');
        $this->assertResponseIsSuccessful();
    }

    public function testCreate(): void
    {
        $crawler = $this->client->request('GET', '/domains/new');
        $this->client->submit($crawler->filter('form')->form(), [
            'domain[name]' => 'functional-create.example.com',
        ]);
        $this->assertResponseRedirects();
    }

    public function testCreateWithoutMatchingParentRedirectsToShow(): void
    {
        $crawler = $this->client->request('GET', '/domains/new');
        $this->client->submit($crawler->filter('form')->form(), [
            'domain[name]' => 'no-reparent-match.example.com',
        ]);

        $domain = $this->em->getRepository(Domain::class)->findOneBy(['name' => 'no-reparent-match.example.com']);
        $this->assertNotNull($domain);
        $this->assertResponseRedirects('/domains/' . $domain->getId());
    }

    public function testCreateWithMatchingParentRecordsRedirectsToRecommendations(): void
    {
        $parent = (new Domain())->setName('reparent-trigger.example.com');
        $this->em->persist($parent);
        $record = (new DomainRecord())
            ->setDomain($parent)
            ->setHostname('host.switches')
            ->setType(RecordType::A)
            ->setValue('10.0.0.1');
        $this->em->persist($record);
        $this->em->flush();
        $this->em->clear(); // evict identity map so Domain::records lazy-loads fresh from the DB

        $crawler = $this->client->request('GET', '/domains/new');
        $this->client->submit($crawler->filter('form')->form(), [
            'domain[name]' => 'switches.reparent-trigger.example.com',
        ]);

        $this->assertResponseRedirects('/recommendations#reparent-dns-card');
    }

    public function testShowLoads(): void
    {
        $view   = $this->makeView('domain-show-view');
        $domain = (new Domain())->setName('functional-show.example.com')->addView($view);
        $this->em->persist($domain);
        $this->em->flush();

        $this->client->request('GET', "/domains/{$domain->getId()}");
        $this->assertResponseIsSuccessful();
    }

    public function testShowSearchIsSavedToUserPreference(): void
    {
        $domain = (new Domain())->setName('search-save.example.com');
        $this->em->persist($domain);
        $this->em->flush();

        $this->client->request('GET', "/domains/{$domain->getId()}?q=web");
        $this->assertResponseIsSuccessful();

        $pref = $this->em->getRepository(UserPreference::class)->findByIdentifier('test@example.com');
        $this->assertSame(['domainId' => $domain->getId(), 'q' => 'web', 'criteria' => []], $pref->getDomainRecordSearch());
    }

    public function testShowRestoresSavedSearchOnPlainVisit(): void
    {
        $domain = (new Domain())->setName('search-restore.example.com');
        $this->em->persist($domain);
        $this->em->flush();
        $domainId = $domain->getId();

        $this->client->request('GET', "/domains/{$domainId}?q=web");

        $this->client->request('GET', "/domains/{$domainId}");
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('value="web"', $this->client->getResponse()->getContent());
    }

    public function testShowDoesNotRestoreSearchSavedForADifferentDomain(): void
    {
        $domainA = (new Domain())->setName('search-scope-a.example.com');
        $domainB = (new Domain())->setName('search-scope-b.example.com');
        $this->em->persist($domainA);
        $this->em->persist($domainB);
        $this->em->flush();

        $this->client->request('GET', "/domains/{$domainA->getId()}?q=web");

        $this->client->request('GET', "/domains/{$domainB->getId()}");
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('value="web"', $this->client->getResponse()->getContent());
    }

    public function testShowResetClearsSavedSearch(): void
    {
        $domain = (new Domain())->setName('search-reset.example.com');
        $this->em->persist($domain);
        $this->em->flush();
        $domainId = $domain->getId();

        $this->client->request('GET', "/domains/{$domainId}?q=web");
        $this->client->request('GET', "/domains/{$domainId}?reset=1");
        $this->assertResponseRedirects("/domains/{$domainId}");

        $pref = $this->em->getRepository(UserPreference::class)->findByIdentifier('test@example.com');
        $this->assertNull($pref->getDomainRecordSearch());
    }

    public function testShowRestoresSavedAdvancedCriteriaOnPlainVisit(): void
    {
        $domain = (new Domain())->setName('search-advanced-restore.example.com');
        $this->em->persist($domain);
        $this->em->flush();
        $domainId = $domain->getId();

        $this->client->request('GET', "/domains/{$domainId}?hostname=web1");

        $this->client->request('GET', "/domains/{$domainId}");
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('value="web1"', $this->client->getResponse()->getContent());
    }

    public function testEditFormLoads(): void
    {
        $view   = $this->makeView('domain-edit-view');
        $domain = (new Domain())->setName('functional-edit.example.com')->addView($view);
        $this->em->persist($domain);
        $this->em->flush();

        $this->client->request('GET', "/domains/{$domain->getId()}/edit");
        $this->assertResponseIsSuccessful();
    }

    public function testUpdate(): void
    {
        $view   = $this->makeView('domain-update-view');
        $domain = (new Domain())->setName('functional-update.example.com')->addView($view);
        $this->em->persist($domain);
        $this->em->flush();

        $crawler = $this->client->request('GET', "/domains/{$domain->getId()}/edit");
        $this->client->submit($crawler->filter('form')->form(), [
            'domain[name]' => 'functional-updated.example.com',
        ]);
        $this->assertResponseRedirects();
    }

    public function testEditFormWithLockedDnssecPolicyRendersSelectExactlyOnce(): void
    {
        $policy = (new DnssecPolicy())->setName('locked-policy-render');
        $view   = $this->makeView('domain-locked-policy-view');
        $domain = (new Domain())->setName('functional-locked-policy.example.com')->addView($view)->setDnssecPolicy($policy);
        $this->em->persist($policy);
        $this->em->persist($domain);
        $this->em->flush();

        $crawler = $this->client->request('GET', "/domains/{$domain->getId()}/edit");
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('select[name="domain[dnssecPolicy]"]'));

        // Submitting must still succeed — a stray un-rendered dnssecPolicy field would
        // otherwise mean the CSRF token field was also swallowed by form_rest suppression.
        $this->client->submit($crawler->filter('form')->form(), [
            'domain[name]' => 'functional-locked-policy-updated.example.com',
        ]);
        $this->assertResponseRedirects();
    }

    public function testDelete(): void
    {
        $view   = $this->makeView('domain-delete-view');
        $domain = (new Domain())->setName('functional-delete.example.com')->addView($view);
        $this->em->persist($domain);
        $this->em->flush();

        $id      = $domain->getId();
        $crawler = $this->client->request('GET', '/domains');
        $this->client->submit(
            $crawler->filter('form[action="/domains/' . $id . '/delete"]')->form()
        );
        $this->assertResponseRedirects();
    }
}
