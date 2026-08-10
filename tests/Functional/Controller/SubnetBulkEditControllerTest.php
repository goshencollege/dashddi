<?php

namespace App\Tests\Functional\Controller;

use App\Entity\DnsView;
use App\Entity\Subnet;
use App\Entity\SubnetRecord;
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

    public function testPostAppliesDefaultTtl(): void
    {
        $s1 = $this->makeSubnet('Bulk Default TTL A', '10.68.1.0/24');
        $s1Id = $s1->getId();

        $this->postBulkEdit(
            [$s1Id],
            ['defaultTtl' => '7200'],
            ['apply_defaultTtl' => '1'],
        );
        $this->assertResponseRedirects('/subnets');

        $this->em->clear();
        $s1 = $this->em->find(Subnet::class, $s1Id);
        $this->assertSame(7200, $s1->getDefaultTtl());
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

    // ── Add DNS Records ───────────────────────────────────────────────────────

    private function postBulkEditWithRecords(array $subnetIds, array $recordTemplates): void
    {
        $idQuery   = implode('&', array_map(fn($id) => 'ids[]=' . $id, $subnetIds));
        $crawler   = $this->client->request('GET', '/subnets/bulk-edit?' . $idQuery);
        $csrfToken = $crawler->filter('input[name="subnet_bulk_edit[_token]"]')->attr('value');

        $postData = [
            'subnet_bulk_edit'  => ['_token' => $csrfToken],
            'subnet_ids'        => $subnetIds,
            'apply_records'     => '1',
            'record_templates'  => $recordTemplates,
        ];

        $this->client->request('POST', '/subnets/bulk-edit', $postData);
    }

    public function testPostAddsRecordToAllSelectedSubnets(): void
    {
        $s1 = $this->makeSubnet('Record Bulk A', '10.73.0.0/24');
        $s2 = $this->makeSubnet('Record Bulk B', '10.74.0.0/24');
        $s1Id = $s1->getId();
        $s2Id = $s2->getId();

        $this->postBulkEditWithRecords(
            [$s1Id, $s2Id],
            [['hostname' => '@', 'type' => 'NS', 'value' => 'ns1.bulk.example.', 'ttl' => '']],
        );
        $this->assertResponseRedirects('/subnets');

        $this->em->clear();
        $s1 = $this->em->find(Subnet::class, $s1Id);
        $s2 = $this->em->find(Subnet::class, $s2Id);
        $this->assertCount(1, $s1->getRecords());
        $this->assertCount(1, $s2->getRecords());
        $this->assertSame('ns1.bulk.example.', $s1->getRecords()->first()->getValue());
    }

    public function testPostAddsMultipleRecordTemplates(): void
    {
        $s1   = $this->makeSubnet('Record Bulk Multi', '10.75.0.0/24');
        $s1Id = $s1->getId();

        $this->postBulkEditWithRecords(
            [$s1Id],
            [
                ['hostname' => '@',   'type' => 'NS', 'value' => 'ns1.bulk.example.', 'ttl' => ''],
                ['hostname' => '@',   'type' => 'NS', 'value' => 'ns2.bulk.example.', 'ttl' => '3600'],
            ],
        );
        $this->assertResponseRedirects('/subnets');

        $this->em->clear();
        $s1     = $this->em->find(Subnet::class, $s1Id);
        $values = $s1->getRecords()->map(fn(SubnetRecord $r) => $r->getValue())->toArray();
        $this->assertContains('ns1.bulk.example.', $values);
        $this->assertContains('ns2.bulk.example.', $values);
        $ttls = $s1->getRecords()->map(fn(SubnetRecord $r) => $r->getTtl())->toArray();
        $this->assertContains(3600, $ttls);
    }

    public function testPostSkipsInvalidRecordTemplate(): void
    {
        $s1   = $this->makeSubnet('Record Bulk Skip', '10.76.0.0/24');
        $s1Id = $s1->getId();

        $this->postBulkEditWithRecords(
            [$s1Id],
            [
                ['hostname' => '',  'type' => 'NS',  'value' => 'ns1.example.',  'ttl' => ''], // blank hostname
                ['hostname' => '@', 'type' => 'NS',  'value' => '',              'ttl' => ''], // blank value
                ['hostname' => '@', 'type' => 'BAD', 'value' => 'ns1.example.', 'ttl' => ''], // bad type
                ['hostname' => '@', 'type' => 'NS',  'value' => 'ns.valid.',     'ttl' => ''], // valid
            ],
        );
        $this->assertResponseRedirects('/subnets');

        $this->em->clear();
        $s1 = $this->em->find(Subnet::class, $s1Id);
        $this->assertCount(1, $s1->getRecords());
    }

    public function testPostRecordViewsScopedToSubnet(): void
    {
        $v1 = (new DnsView())->setName('bulk-rec-view-1');
        $v2 = (new DnsView())->setName('bulk-rec-view-2');
        $this->em->persist($v1);
        $this->em->persist($v2);

        // s1 has only v1; s2 has both
        $s1 = (new Subnet())->setName('Rec View Subnet A')->setIpv4Cidr('10.77.0.0/24');
        $s1->addView($v1);
        $s2 = (new Subnet())->setName('Rec View Subnet B')->setIpv4Cidr('10.78.0.0/24');
        $s2->addView($v1)->addView($v2);
        $this->em->persist($s1);
        $this->em->persist($s2);
        $this->em->flush();
        $s1Id = $s1->getId();
        $s2Id = $s2->getId();

        // Request both v1 and v2 for the record template
        $this->postBulkEditWithRecords(
            [$s1Id, $s2Id],
            [['hostname' => '@', 'type' => 'NS', 'value' => 'ns1.example.', 'ttl' => '',
              'views' => [$v1->getId(), $v2->getId()]]],
        );
        $this->assertResponseRedirects('/subnets');

        $this->em->clear();
        $s1 = $this->em->find(Subnet::class, $s1Id);
        $s2 = $this->em->find(Subnet::class, $s2Id);

        $s1RecordViews = $s1->getRecords()->first()->getViews()->map(fn(DnsView $v) => $v->getName())->toArray();
        $this->assertContains('bulk-rec-view-1', $s1RecordViews);
        $this->assertNotContains('bulk-rec-view-2', $s1RecordViews); // s1 doesn't have v2

        $s2RecordViews = $s2->getRecords()->first()->getViews()->map(fn(DnsView $v) => $v->getName())->toArray();
        $this->assertContains('bulk-rec-view-1', $s2RecordViews);
        $this->assertContains('bulk-rec-view-2', $s2RecordViews); // s2 has both
    }

    public function testPostRecordsNotAddedWhenApplyUnchecked(): void
    {
        $s1   = $this->makeSubnet('Record No Apply', '10.79.0.0/24');
        $s1Id = $s1->getId();

        $idQuery   = "ids[]={$s1Id}";
        $crawler   = $this->client->request('GET', '/subnets/bulk-edit?' . $idQuery);
        $csrfToken = $crawler->filter('input[name="subnet_bulk_edit[_token]"]')->attr('value');

        // POST with record_templates but WITHOUT apply_records
        $this->client->request('POST', '/subnets/bulk-edit', [
            'subnet_bulk_edit' => ['_token' => $csrfToken],
            'subnet_ids'       => [$s1Id],
            'record_templates' => [['hostname' => '@', 'type' => 'NS', 'value' => 'ns1.example.', 'ttl' => '']],
            // apply_records intentionally omitted
        ]);
        $this->assertResponseRedirects('/subnets');

        $this->em->clear();
        $s1 = $this->em->find(Subnet::class, $s1Id);
        $this->assertCount(0, $s1->getRecords());
    }
}
