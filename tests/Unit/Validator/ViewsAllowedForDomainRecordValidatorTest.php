<?php

namespace App\Tests\Unit\Validator;

use App\Entity\DnsView;
use App\Entity\Domain;
use App\Entity\DomainRecord;
use App\Entity\NetworkInterface;
use App\Entity\Subnet;
use App\Enum\RecordType;
use App\Service\DnsViewResolver;
use App\Validator\ViewsAllowedForDomainRecord;
use App\Validator\ViewsAllowedForDomainRecordValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

class ViewsAllowedForDomainRecordValidatorTest extends TestCase
{
    private DnsViewResolver $resolver;
    private ViewsAllowedForDomainRecordValidator $validator;
    private ExecutionContextInterface&MockObject $context;

    protected function setUp(): void
    {
        $this->resolver  = $this->createStub(DnsViewResolver::class);
        $this->validator = new ViewsAllowedForDomainRecordValidator($this->resolver);

        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->validator->initialize($this->context);
    }

    private function setId(object $entity, int $id): void
    {
        $rp = new ReflectionProperty($entity, 'id');
        $rp->setValue($entity, $id);
    }

    private function makeView(int $id, string $name): DnsView
    {
        $view = (new DnsView())->setName($name);
        $this->setId($view, $id);
        return $view;
    }

    private function makeRecord(RecordType $type, ?NetworkInterface $iface, Domain $domain, DnsView ...$views): DomainRecord
    {
        $record = new DomainRecord();
        $record->setType($type);
        $record->setDomain($domain);
        $record->setNetworkInterface($iface);
        foreach ($views as $view) {
            $record->addView($view);
        }
        return $record;
    }

    public function testNoViolationWhenNoInterface(): void
    {
        $this->context->expects($this->never())->method('buildViolation');

        $domain = new Domain();
        $view   = $this->makeView(1, 'external');
        $record = $this->makeRecord(RecordType::A, null, $domain, $view);

        $this->validator->validate($record, new ViewsAllowedForDomainRecord());
    }

    public function testNoViolationWhenViewAllowedForSubnet(): void
    {
        $this->context->expects($this->never())->method('buildViolation');

        $view   = $this->makeView(1, 'internal');
        $domain = new Domain();
        $subnet = new Subnet();
        $iface  = (new NetworkInterface())->setSubnet($subnet);
        $record = $this->makeRecord(RecordType::A, $iface, $domain, $view);

        $this->resolver->method('availableViewsFor')->willReturn([$view]);

        $this->validator->validate($record, new ViewsAllowedForDomainRecord());
    }

    public function testViolationWhenAddressRecordViewNotAllowedForSubnet(): void
    {
        $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $builder->method('setParameter')->willReturnSelf();
        $builder->expects($this->once())->method('addViolation');

        $this->context->expects($this->once())
            ->method('buildViolation')
            ->willReturn($builder);

        $externalView = $this->makeView(1, 'external');
        $internalView = $this->makeView(2, 'internal');
        $domain       = new Domain();
        $subnet       = new Subnet();
        $iface        = (new NetworkInterface())->setSubnet($subnet);
        // A record assigned to external view, but subnet only allows internal view
        $record = $this->makeRecord(RecordType::A, $iface, $domain, $externalView);

        $this->resolver->method('availableViewsFor')->willReturn([$internalView]);

        $this->validator->validate($record, new ViewsAllowedForDomainRecord());
    }

    public function testNoViolationForTxtRecordEvenWhenViewNotAllowedForSubnet(): void
    {
        $this->context->expects($this->never())->method('buildViolation');

        $externalView = $this->makeView(1, 'external');
        $internalView = $this->makeView(2, 'internal');
        $domain       = new Domain();
        $subnet       = new Subnet();
        $iface        = (new NetworkInterface())->setSubnet($subnet);
        // TXT record (e.g. ACME challenge) assigned to external view;
        // subnet only allows internal view — should not trigger a violation.
        $record = $this->makeRecord(RecordType::TXT, $iface, $domain, $externalView);

        $this->resolver->method('availableViewsFor')->willReturn([$internalView]);

        $this->validator->validate($record, new ViewsAllowedForDomainRecord());
    }

    public function testViolationOnlyForAddressTypes(): void
    {
        // AAAA records are also address records and must respect the subnet restriction.
        $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $builder->method('setParameter')->willReturnSelf();
        $builder->expects($this->once())->method('addViolation');

        $this->context->expects($this->once())
            ->method('buildViolation')
            ->willReturn($builder);

        $externalView = $this->makeView(1, 'external');
        $internalView = $this->makeView(2, 'internal');
        $domain       = new Domain();
        $subnet       = new Subnet();
        $iface        = (new NetworkInterface())->setSubnet($subnet);
        $record       = $this->makeRecord(RecordType::AAAA, $iface, $domain, $externalView);

        $this->resolver->method('availableViewsFor')->willReturn([$internalView]);

        $this->validator->validate($record, new ViewsAllowedForDomainRecord());
    }
}
