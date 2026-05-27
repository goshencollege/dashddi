<?php

namespace App\Tests\Unit\Validator;

use App\Entity\NetworkInterface;
use App\Repository\NetworkInterfaceRepository;
use App\Validator\UniqueMacAddress;
use App\Validator\UniqueMacAddressValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

class UniqueMacAddressValidatorTest extends TestCase
{
    private NetworkInterfaceRepository $repo;
    private UniqueMacAddressValidator $validator;
    private ExecutionContextInterface&MockObject $context;

    protected function setUp(): void
    {
        $this->repo = $this->createStub(NetworkInterfaceRepository::class);
        $this->validator = new UniqueMacAddressValidator($this->repo);

        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->validator->initialize($this->context);
    }

    public function testNoViolationForZeroMac(): void
    {
        $iface = new NetworkInterface();
        // macAddress defaults to 00:00:00:00:00:00

        $this->context->expects($this->never())->method('buildViolation');

        $this->validator->validate($iface, new UniqueMacAddress());
    }

    public function testNoViolationWhenMacIsUnique(): void
    {
        $iface = new NetworkInterface();
        $iface->setMacAddress('aa:bb:cc:dd:ee:ff');

        $this->repo->method('findOneBy')->willReturn(null);
        $this->context->expects($this->never())->method('buildViolation');

        $this->validator->validate($iface, new UniqueMacAddress());
    }

    public function testViolationWhenMacBelongsToAnotherInterface(): void
    {
        $iface = new NetworkInterface();
        $iface->setMacAddress('aa:bb:cc:dd:ee:ff');

        $other = new NetworkInterface();
        $prop = new \ReflectionProperty(NetworkInterface::class, 'id');
        $prop->setValue($other, 99);

        $this->repo->method('findOneBy')->willReturn($other);

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('atPath')->willReturnSelf();
        $violationBuilder->expects($this->once())->method('addViolation');

        $this->context->expects($this->once())
            ->method('buildViolation')
            ->willReturn($violationBuilder);

        $this->validator->validate($iface, new UniqueMacAddress());
    }

    public function testNoViolationWhenMacBelongsToSameInterface(): void
    {
        $iface = new NetworkInterface();
        $iface->setMacAddress('aa:bb:cc:dd:ee:ff');
        $prop = new \ReflectionProperty(NetworkInterface::class, 'id');
        $prop->setValue($iface, 42);

        // Repo returns the same interface (editing, keeping the same MAC)
        $this->repo->method('findOneBy')->willReturn($iface);
        $this->context->expects($this->never())->method('buildViolation');

        $this->validator->validate($iface, new UniqueMacAddress());
    }

    public function testSkipsValidationForNonNetworkInterfaceValue(): void
    {
        $this->context->expects($this->never())->method('buildViolation');

        $this->validator->validate('not-an-interface', new UniqueMacAddress());
    }
}
