<?php

namespace App\Tests\Unit\Validator;

use App\Entity\Host;
use App\Repository\HostRepository;
use App\Validator\UniqueDuid;
use App\Validator\UniqueDuidValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

class UniqueDuidValidatorTest extends TestCase
{
    private HostRepository $repo;
    private UniqueDuidValidator $validator;
    private ExecutionContextInterface&MockObject $context;

    protected function setUp(): void
    {
        $this->repo = $this->createStub(HostRepository::class);
        $this->validator = new UniqueDuidValidator($this->repo);

        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->validator->initialize($this->context);
    }

    public function testNoViolationForBlankDuid(): void
    {
        $host = new Host();
        // duid defaults to null

        $this->context->expects($this->never())->method('buildViolation');

        $this->validator->validate($host, new UniqueDuid());
    }

    public function testNoViolationWhenDuidIsUnique(): void
    {
        $host = new Host();
        $host->setDuid('00:01:00:01:2b:3c:4d:5e:aa:bb:cc:dd:ee:ff');

        $this->repo->method('findOneBy')->willReturn(null);
        $this->context->expects($this->never())->method('buildViolation');

        $this->validator->validate($host, new UniqueDuid());
    }

    public function testViolationWhenDuidBelongsToAnotherHost(): void
    {
        $host = new Host();
        $host->setDuid('00:01:00:01:2b:3c:4d:5e:aa:bb:cc:dd:ee:ff');

        $other = new Host();
        $prop = new \ReflectionProperty(Host::class, 'id');
        $prop->setValue($other, 99);

        $this->repo->method('findOneBy')->willReturn($other);

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('atPath')->willReturnSelf();
        $violationBuilder->expects($this->once())->method('addViolation');

        $this->context->expects($this->once())
            ->method('buildViolation')
            ->willReturn($violationBuilder);

        $this->validator->validate($host, new UniqueDuid());
    }

    public function testNoViolationWhenDuidBelongsToSameHost(): void
    {
        $host = new Host();
        $host->setDuid('00:01:00:01:2b:3c:4d:5e:aa:bb:cc:dd:ee:ff');
        $prop = new \ReflectionProperty(Host::class, 'id');
        $prop->setValue($host, 42);

        // Repo returns the same host (editing, keeping the same DUID)
        $this->repo->method('findOneBy')->willReturn($host);
        $this->context->expects($this->never())->method('buildViolation');

        $this->validator->validate($host, new UniqueDuid());
    }

    public function testSkipsValidationForNonHostValue(): void
    {
        $this->context->expects($this->never())->method('buildViolation');

        $this->validator->validate('not-a-host', new UniqueDuid());
    }
}
