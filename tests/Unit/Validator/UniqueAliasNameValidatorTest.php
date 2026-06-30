<?php

namespace App\Tests\Unit\Validator;

use App\Entity\Domain;
use App\Entity\DomainAlias;
use App\Repository\DomainAliasRepository;
use App\Repository\DomainRepository;
use App\Validator\UniqueAliasName;
use App\Validator\UniqueAliasNameValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

class UniqueAliasNameValidatorTest extends TestCase
{
    private DomainAliasRepository $aliasRepo;
    private DomainRepository $domainRepo;
    private UniqueAliasNameValidator $validator;
    private ExecutionContextInterface&MockObject $context;

    protected function setUp(): void
    {
        $this->aliasRepo  = $this->createStub(DomainAliasRepository::class);
        $this->domainRepo = $this->createStub(DomainRepository::class);
        $this->validator  = new UniqueAliasNameValidator($this->aliasRepo, $this->domainRepo);

        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->validator->initialize($this->context);
    }

    private function makeAlias(string $name, ?int $id = null): DomainAlias
    {
        $domain = new Domain();
        $alias  = new DomainAlias();
        $alias->setDomain($domain)->setName($name);
        if ($id !== null) {
            $prop = new \ReflectionProperty(DomainAlias::class, 'id');
            $prop->setValue($alias, $id);
        }
        return $alias;
    }

    public function testNoViolationWhenNameIsUnique(): void
    {
        $this->aliasRepo->method('findByName')->willReturn(null);
        $this->domainRepo->method('findOneBy')->willReturn(null);
        $this->context->expects($this->never())->method('buildViolation');

        $this->validator->validate($this->makeAlias('example.net'), new UniqueAliasName());
    }

    public function testViolationWhenNameUsedByAnotherAlias(): void
    {
        $existing = $this->makeAlias('example.net', 99);
        $this->aliasRepo->method('findByName')->willReturn($existing);

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('atPath')->willReturnSelf();
        $violationBuilder->expects($this->once())->method('addViolation');

        $this->context->expects($this->once())
            ->method('buildViolation')
            ->with((new UniqueAliasName())->messageDuplicateAlias)
            ->willReturn($violationBuilder);

        $this->validator->validate($this->makeAlias('example.net', 1), new UniqueAliasName());
    }

    public function testNoViolationWhenAliasMatchesItself(): void
    {
        $alias = $this->makeAlias('example.net', 42);
        $this->aliasRepo->method('findByName')->willReturn($alias);
        $this->domainRepo->method('findOneBy')->willReturn(null);
        $this->context->expects($this->never())->method('buildViolation');

        $this->validator->validate($alias, new UniqueAliasName());
    }

    public function testViolationWhenNameMatchesExistingDomain(): void
    {
        $this->aliasRepo->method('findByName')->willReturn(null);
        $this->domainRepo->method('findOneBy')->willReturn(new Domain());

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('atPath')->willReturnSelf();
        $violationBuilder->expects($this->once())->method('addViolation');

        $this->context->expects($this->once())
            ->method('buildViolation')
            ->with((new UniqueAliasName())->messageDuplicateDomain)
            ->willReturn($violationBuilder);

        $this->validator->validate($this->makeAlias('example.com'), new UniqueAliasName());
    }

    public function testSkipsValidationForNonAliasValue(): void
    {
        $this->context->expects($this->never())->method('buildViolation');

        $this->validator->validate('not-an-alias', new UniqueAliasName());
    }
}
