<?php

namespace App\Tests\Functional\Controller;

use App\Entity\DnsView;
use App\Entity\Subnet;
use App\Entity\SubnetRecord;
use App\Enum\RecordType;
use App\Tests\Functional\AppWebTestCase;

class SubnetRecordControllerTest extends AppWebTestCase
{
    private function makeSubnet(string $name = 'Record Test Subnet'): Subnet
    {
        $subnet = (new Subnet())->setName($name)->setIpv4Cidr('10.50.0.0/24');
        $this->em->persist($subnet);
        $this->em->flush();
        return $subnet;
    }

    private function makeSubnetWithView(string $subnetName, string $viewName): array
    {
        $view   = (new DnsView())->setName($viewName);
        $subnet = (new Subnet())->setName($subnetName)->setIpv4Cidr('10.51.0.0/24');
        $subnet->addView($view);
        $this->em->persist($view);
        $this->em->persist($subnet);
        $this->em->flush();
        return [$subnet, $view];
    }

    private function makeRecord(Subnet $subnet): SubnetRecord
    {
        $record = new SubnetRecord();
        $record->setSubnet($subnet)->setHostname('@')->setType(RecordType::NS)->setValue('ns2.example.com.');
        $this->em->persist($record);
        $this->em->flush();
        return $record;
    }

    public function testNewFormLoads(): void
    {
        $subnet = $this->makeSubnet();
        $this->client->request('GET', "/subnet/{$subnet->getId()}/records/new");
        $this->assertResponseIsSuccessful();
    }

    public function testNewFormPreSelectsSubnetViews(): void
    {
        [$subnet] = $this->makeSubnetWithView('View Subnet', 'sr-view');
        $crawler = $this->client->request('GET', "/subnet/{$subnet->getId()}/records/new");
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('input[type=checkbox][name*="views"]'));
    }

    public function testCreate(): void
    {
        $subnet  = $this->makeSubnet('Create Record Subnet');
        $crawler = $this->client->request('GET', "/subnet/{$subnet->getId()}/records/new");
        $this->client->submit($crawler->filter('form')->form(), [
            'subnet_record[hostname]' => '@',
            'subnet_record[type]'     => 'NS',
            'subnet_record[value]'    => 'ns2.example.com.',
        ]);
        $this->assertResponseRedirects("/subnets/{$subnet->getId()}");
    }

    public function testEditFormLoads(): void
    {
        $subnet = $this->makeSubnet('Edit Record Subnet');
        $record = $this->makeRecord($subnet);

        $this->client->request('GET', "/subnet-records/{$record->getId()}/edit");
        $this->assertResponseIsSuccessful();
    }

    public function testUpdate(): void
    {
        $subnet   = $this->makeSubnet('Update Record Subnet');
        $record   = $this->makeRecord($subnet);
        $recordId = $record->getId();

        $crawler = $this->client->request('GET', "/subnet-records/{$recordId}/edit");
        $this->client->submit($crawler->filter('form')->form(), [
            'subnet_record[hostname]' => 'ns-extra',
            'subnet_record[type]'     => 'NS',
            'subnet_record[value]'    => 'ns3.example.com.',
        ]);
        $this->assertResponseRedirects("/subnets/{$subnet->getId()}");

        $this->em->clear();
        $updated = $this->em->find(SubnetRecord::class, $recordId);
        $this->assertSame('ns-extra', $updated->getHostname());
        $this->assertSame('ns3.example.com.', $updated->getValue());
    }

    public function testDelete(): void
    {
        $subnet   = $this->makeSubnet('Delete Record Subnet');
        $record   = $this->makeRecord($subnet);
        $id       = $record->getId();
        $subnetId = $subnet->getId();

        $this->em->clear();
        $crawler = $this->client->request('GET', "/subnets/{$subnetId}");
        $this->client->submit(
            $crawler->filter("form[action='/subnet-records/{$id}/delete']")->form()
        );
        $this->assertResponseRedirects("/subnets/{$subnetId}");

        $this->em->clear();
        $this->assertNull($this->em->find(SubnetRecord::class, $id));
    }

    public function testShowPageListsRecord(): void
    {
        $subnet   = $this->makeSubnet('Show Record Subnet');
        $this->makeRecord($subnet);
        $subnetId = $subnet->getId();

        $this->em->clear();
        $crawler = $this->client->request('GET', "/subnets/{$subnetId}");
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('ns2.example.com.', $crawler->text());
    }

}
