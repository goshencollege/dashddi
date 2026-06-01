<?php

namespace App\Tests\Functional\Controller;

use App\Entity\DnsView;
use App\Entity\Subnet;
use App\Entity\Tag;
use App\Tests\Functional\AppWebTestCase;

class SubnetBulkEditControllerTest extends AppWebTestCase
{
    private function makeSubnet(string $name, string $cidr = '10.60.0.0/24'): Subnet
    {
        $subnet = (new Subnet())->setName($name)->setIpv4Cidr($cidr);
        $this->em->persist($subnet);
        $this->em->flush();
        return $subnet;
    }

    // ── GET ──────────────────────────────────────────────────────────────────

    public function testGetWithNoIdsRedirects(): void
    {
        $this->client->request('GET', '/subnets/bulk-edit');
        $this->assertResponseRedirects('/subnets');
    }

    public function testGetWithUnknownIdsRedirects(): void
    {
        $this->client->request('GET', '/subnets/bulk-edit?ids[]=999999');
        $this->assertResponseRedirects('/subnets');
    }

    public function testGetWithValidIdsLoadsForm(): void
    {
        $s1 = $this->makeSubnet('Bulk Get A', '10.61.0.0/24');
        $s2 = $this->makeSubnet('Bulk Get B', '10.62.0.0/24');

        $this->client->request('GET', sprintf(
            '/subnets/bulk-edit?ids[]=%d&ids[]=%d', $s1->getId(), $s2->getId()
        ));
        $this->assertResponseIsSuccessful();
    }

    public function testGetShowsSelectedSubnetNames(): void
    {
        $subnet = $this->makeSubnet('Unique Subnet XYZ', '10.63.0.0/24');

        $crawler = $this->client->request('GET', "/subnets/bulk-edit?ids[]={$subnet->getId()}");
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Unique Subnet XYZ', $crawler->text());
    }

    // ── POST — field application ──────────────────────────────────────────────

    private function postBulkEdit(array $subnetIds, array $formFields, array $applyFields): void
    {
        // Get the form page first to extract the CSRF token
        $idQuery = implode('&', array_map(fn($id) => 'ids[]=' . $id, $subnetIds));
        $crawler = $this->client->request('GET', '/subnets/bulk-edit?' . $idQuery);

        $csrfToken = $crawler->filter('input[name="subnet_bulk_edit[_token]"]')->attr('value');

        $postData = array_merge(
            ['subnet_bulk_edit' => array_merge($formFields, ['_token' => $csrfToken])],
            ['subnet_ids'       => $subnetIds],
            $applyFields,
        );

        $this->client->request('POST', '/subnets/bulk-edit', $postData);
    }

    public function testPostAppliesVlanToAllSelected(): void
    {
        $s1 = $this->makeSubnet('Bulk Vlan A', '10.64.0.0/24');
        $s2 = $this->makeSubnet('Bulk Vlan B', '10.65.0.0/24');

        $this->postBulkEdit(
            [$s1->getId(), $s2->getId()],
            ['vlan' => '200'],
            ['apply_vlan' => '1'],
        );
        $this->assertResponseRedirects('/subnets');

        $s1Id = $s1->getId();
        $s2Id = $s2->getId();
        $this->em->clear();
        $s1 = $this->em->find(Subnet::class, $s1Id);
        $s2 = $this->em->find(Subnet::class, $s2Id);
        $this->assertSame(200, $s1->getVlan());
        $this->assertSame(200, $s2->getVlan());
    }

    public function testPostUncheckedFieldIsNotApplied(): void
    {
        $s1 = $this->makeSubnet('Bulk Skip A', '10.66.0.0/24');
        $s1->setVlan(100);
        $this->em->flush();
        $s1Id = $s1->getId();

        // Submit with a vlan value but WITHOUT apply_vlan checked
        $this->postBulkEdit(
            [$s1Id],
            ['vlan' => '999'],
            [], // apply_vlan not included → not applied
        );
        $this->assertResponseRedirects('/subnets');

        $this->em->clear();
        $s1 = $this->em->find(Subnet::class, $s1Id);
        $this->assertSame(100, $s1->getVlan()); // unchanged
    }

    public function testPostAppliesDescription(): void
    {
        $s1 = $this->makeSubnet('Bulk Desc A', '10.67.0.0/24');
        $s1Id = $s1->getId();

        $this->postBulkEdit(
            [$s1Id],
            ['description' => 'Bulk-assigned description'],
            ['apply_description' => '1'],
        );
        $this->assertResponseRedirects('/subnets');

        $this->em->clear();
        $s1 = $this->em->find(Subnet::class, $s1Id);
        $this->assertSame('Bulk-assigned description', $s1->getDescription());
    }

    public function testPostAppliesSoaFields(): void
    {
        $s1 = $this->makeSubnet('Bulk SOA A', '10.68.0.0/24');
        $s1Id = $s1->getId();

        $this->postBulkEdit(
            [$s1Id],
            [
                'soaNameserver' => 'ns1.test.example.',
                'soaEmail'      => 'hostmaster@test.example',
                'soaRefresh'    => '7200',
                'soaRetry'      => '1800',
                'soaExpire'     => '86400',
                'soaTtl'        => '300',
            ],
            [
                'apply_soaNameserver' => '1',
                'apply_soaEmail'      => '1',
                'apply_soaRefresh'    => '1',
                'apply_soaRetry'      => '1',
                'apply_soaExpire'     => '1',
                'apply_soaTtl'        => '1',
            ],
        );
        $this->assertResponseRedirects('/subnets');

        $this->em->clear();
        $s1 = $this->em->find(Subnet::class, $s1Id);
        $this->assertSame('ns1.test.example.', $s1->getSoaNameserver());
        $this->assertSame('hostmaster@test.example', $s1->getSoaEmail());
        $this->assertSame(7200, $s1->getSoaRefresh());
        $this->assertSame(1800, $s1->getSoaRetry());
        $this->assertSame(86400, $s1->getSoaExpire());
        $this->assertSame(300, $s1->getSoaTtl());
    }

    public function testPostTagsReplaceMode(): void
    {
        $existing = (new Tag())->setName('existing-tag');
        $new      = (new Tag())->setName('new-tag');
        $this->em->persist($existing);
        $this->em->persist($new);

        $s1 = (new Subnet())->setName('Bulk Tag A')->setIpv4Cidr('10.69.0.0/24');
        $s1->addTag($existing);
        $this->em->persist($s1);
        $this->em->flush();
        $s1Id = $s1->getId();

        $idQuery   = "ids[]={$s1Id}";
        $crawler   = $this->client->request('GET', '/subnets/bulk-edit?' . $idQuery);
        $csrfToken = $crawler->filter('input[name="subnet_bulk_edit[_token]"]')->attr('value');

        $this->client->request('POST', '/subnets/bulk-edit', [
            'subnet_bulk_edit' => ['tags' => [$new->getId()], '_token' => $csrfToken],
            'subnet_ids'       => [$s1Id],
            'apply_tags'       => '1',
            'tags_mode'        => 'replace',
        ]);
        $this->assertResponseRedirects('/subnets');

        $this->em->clear();
        $s1      = $this->em->find(Subnet::class, $s1Id);
        $tagNames = $s1->getTags()->map(fn(Tag $t) => $t->getName())->toArray();
        $this->assertContains('new-tag', $tagNames);
        $this->assertNotContains('existing-tag', $tagNames);
    }

    public function testPostTagsAddMode(): void
    {
        $existing = (new Tag())->setName('add-existing-tag');
        $new      = (new Tag())->setName('add-new-tag');
        $this->em->persist($existing);
        $this->em->persist($new);

        $s1 = (new Subnet())->setName('Bulk Tag Add')->setIpv4Cidr('10.70.0.0/24');
        $s1->addTag($existing);
        $this->em->persist($s1);
        $this->em->flush();
        $s1Id = $s1->getId();

        $idQuery   = "ids[]={$s1Id}";
        $crawler   = $this->client->request('GET', '/subnets/bulk-edit?' . $idQuery);
        $csrfToken = $crawler->filter('input[name="subnet_bulk_edit[_token]"]')->attr('value');

        $this->client->request('POST', '/subnets/bulk-edit', [
            'subnet_bulk_edit' => ['tags' => [$new->getId()], '_token' => $csrfToken],
            'subnet_ids'       => [$s1Id],
            'apply_tags'       => '1',
            'tags_mode'        => 'add',
        ]);
        $this->assertResponseRedirects('/subnets');

        $this->em->clear();
        $s1      = $this->em->find(Subnet::class, $s1Id);
        $tagNames = $s1->getTags()->map(fn(Tag $t) => $t->getName())->toArray();
        $this->assertContains('add-existing-tag', $tagNames);
        $this->assertContains('add-new-tag', $tagNames);
    }

    public function testPostDnsViewsReplaceMode(): void
    {
        $v1 = (new DnsView())->setName('bulk-view-1');
        $v2 = (new DnsView())->setName('bulk-view-2');
        $this->em->persist($v1);
        $this->em->persist($v2);

        $s1 = (new Subnet())->setName('Bulk Views A')->setIpv4Cidr('10.71.0.0/24');
        $s1->addView($v1);
        $this->em->persist($s1);
        $this->em->flush();
        $s1Id = $s1->getId();

        $idQuery   = "ids[]={$s1Id}";
        $crawler   = $this->client->request('GET', '/subnets/bulk-edit?' . $idQuery);
        $csrfToken = $crawler->filter('input[name="subnet_bulk_edit[_token]"]')->attr('value');

        $this->client->request('POST', '/subnets/bulk-edit', [
            'subnet_bulk_edit' => ['views' => [$v2->getId()], '_token' => $csrfToken],
            'subnet_ids'       => [$s1Id],
            'apply_views'      => '1',
            'views_mode'       => 'replace',
        ]);
        $this->assertResponseRedirects('/subnets');

        $this->em->clear();
        $s1       = $this->em->find(Subnet::class, $s1Id);
        $viewNames = $s1->getViews()->map(fn(DnsView $v) => $v->getName())->toArray();
        $this->assertContains('bulk-view-2', $viewNames);
        $this->assertNotContains('bulk-view-1', $viewNames);
    }

    public function testPostLeaseRetentionDays(): void
    {
        $s1 = $this->makeSubnet('Bulk Retention A', '10.72.0.0/24');
        $s1Id = $s1->getId();

        $this->postBulkEdit(
            [$s1Id],
            ['leaseRetentionDays' => '90'],
            ['apply_leaseRetentionDays' => '1'],
        );
        $this->assertResponseRedirects('/subnets');

        $this->em->clear();
        $s1 = $this->em->find(Subnet::class, $s1Id);
        $this->assertSame(90, $s1->getLeaseRetentionDays());
    }
}
