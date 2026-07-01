<?php

namespace App\Tests\Unit\Validator;

use App\Entity\Domain;
use App\Entity\DomainRecord;
use App\Enum\RecordType;
use App\Repository\DomainRecordRepository;
use App\Validator\NoCnameConflict;
use App\Validator\NoCnameConflictValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

class NoCnameConflictValidatorTest extends TestCase
{
    private function makeRecord(RecordType $type, string $hostname, ?Domain $domain = null, ?int $id = null): DomainRecord
    {
        $record = new DomainRecord();
        $record->setType($type);
        $record->setHostname($hostname);
        if ($domain !== null) {
            $record->setDomain($domain);
        }
        if ($id !== null) {
            $rp = new \ReflectionProperty(DomainRecord::class, 'id');
            $rp->setValue($record, $id);
        }
        return $record;
    }

    private function expectViolationOnce(ExecutionContextInterface $context): void
    {
        $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $builder->method('setParameter')->willReturnSelf();
        $builder->method('atPath')->willReturnSelf();
        $builder->expects($this->once())->method('addViolation');
        $context->expects($this->once())->method('buildViolation')->willReturn($builder);
    }

    private function expectNoViolation(ExecutionContextInterface $context): void
    {
        $context->expects($this->never())->method('buildViolation');
    }

    private function makeValidator(DomainRecordRepository $repo): NoCnameConflictValidator
    {
        return new NoCnameConflictValidator($repo);
    }

    public function testCnameAtApexAddsViolation(): void
    {
        $record    = $this->makeRecord(RecordType::CNAME, '@', new Domain());
        $repo      = $this->createStub(DomainRecordRepository::class);
        $validator = $this->makeValidator($repo);
        $context   = $this->createMock(ExecutionContextInterface::class);

        $this->expectViolationOnce($context);
        $validator->initialize($context);
        $validator->validate($record, new NoCnameConflict());
    }

    public function testCnameWithExistingRecordsAddsViolation(): void
    {
        $record = $this->makeRecord(RecordType::CNAME, 'www', new Domain());
        $repo   = $this->createStub(DomainRecordRepository::class);
        $repo->method('hasOtherRecordsForHostname')->willReturn(true);

        $validator = $this->makeValidator($repo);
        $context   = $this->createMock(ExecutionContextInterface::class);

        $this->expectViolationOnce($context);
        $validator->initialize($context);
        $validator->validate($record, new NoCnameConflict());
    }

    public function testNonCnameWithExistingCnameAddsViolation(): void
    {
        $record = $this->makeRecord(RecordType::A, 'www', new Domain());
        $repo   = $this->createStub(DomainRecordRepository::class);
        $repo->method('hasCnameForHostname')->willReturn(true);

        $validator = $this->makeValidator($repo);
        $context   = $this->createMock(ExecutionContextInterface::class);

        $this->expectViolationOnce($context);
        $validator->initialize($context);
        $validator->validate($record, new NoCnameConflict());
    }

    public function testCnameWithNoConflictPassesValidation(): void
    {
        $record = $this->makeRecord(RecordType::CNAME, 'www', new Domain());
        $repo   = $this->createStub(DomainRecordRepository::class);
        $repo->method('hasOtherRecordsForHostname')->willReturn(false);

        $validator = $this->makeValidator($repo);
        $context   = $this->createMock(ExecutionContextInterface::class);

        $this->expectNoViolation($context);
        $validator->initialize($context);
        $validator->validate($record, new NoCnameConflict());
    }

    public function testNonCnameWithNoCnameConflictPassesValidation(): void
    {
        $record = $this->makeRecord(RecordType::A, 'www', new Domain());
        $repo   = $this->createStub(DomainRecordRepository::class);
        $repo->method('hasCnameForHostname')->willReturn(false);

        $validator = $this->makeValidator($repo);
        $context   = $this->createMock(ExecutionContextInterface::class);

        $this->expectNoViolation($context);
        $validator->initialize($context);
        $validator->validate($record, new NoCnameConflict());
    }

    public function testSelfEditExcludesCurrentRecordId(): void
    {
        $domain = new Domain();
        $record = $this->makeRecord(RecordType::CNAME, 'www', $domain, 42);

        $repo = $this->createMock(DomainRecordRepository::class);
        $repo->expects($this->once())
            ->method('hasOtherRecordsForHostname')
            ->with($domain, 'www', 42, [])
            ->willReturn(false);

        $validator = $this->makeValidator($repo);
        $context   = $this->createMock(ExecutionContextInterface::class);

        $this->expectNoViolation($context);
        $validator->initialize($context);
        $validator->validate($record, new NoCnameConflict());
    }

    public function testNoDomainSkipsValidation(): void
    {
        $record = $this->makeRecord(RecordType::CNAME, 'www');

        $repo = $this->createMock(DomainRecordRepository::class);
        $repo->expects($this->never())->method('hasOtherRecordsForHostname');
        $repo->expects($this->never())->method('hasCnameForHostname');

        $validator = $this->makeValidator($repo);
        $context   = $this->createMock(ExecutionContextInterface::class);

        $this->expectNoViolation($context);
        $validator->initialize($context);
        $validator->validate($record, new NoCnameConflict());
    }
}
