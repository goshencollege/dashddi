<?php

namespace App\Tests\Unit\Validator;

use App\Entity\Domain;
use App\Entity\DomainRecord;
use App\Enum\RecordType;
use App\Repository\DomainRecordRepository;
use App\Validator\NoMultipleSpfTxt;
use App\Validator\NoMultipleSpfTxtValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

class NoMultipleSpfTxtValidatorTest extends TestCase
{
    private function makeRecord(RecordType $type, string $hostname, string $value, ?Domain $domain = null, ?int $id = null): DomainRecord
    {
        $record = new DomainRecord();
        $record->setType($type);
        $record->setHostname($hostname);
        $record->setValue($value);
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

    public function testSecondSpfRecordAtSameHostnameAddsViolation(): void
    {
        $domain = new Domain();
        $record = $this->makeRecord(RecordType::TXT, '@', 'v=spf1 -all', $domain);

        $repo = $this->createStub(DomainRecordRepository::class);
        $repo->method('hasOtherSpfTxtForHostname')->willReturn(true);

        $validator = new NoMultipleSpfTxtValidator($repo);
        $context   = $this->createMock(ExecutionContextInterface::class);

        $this->expectViolationOnce($context);
        $validator->initialize($context);
        $validator->validate($record, new NoMultipleSpfTxt());
    }

    public function testFirstSpfRecordPassesValidation(): void
    {
        $domain = new Domain();
        $record = $this->makeRecord(RecordType::TXT, '@', 'v=spf1 -all', $domain);

        $repo = $this->createStub(DomainRecordRepository::class);
        $repo->method('hasOtherSpfTxtForHostname')->willReturn(false);

        $validator = new NoMultipleSpfTxtValidator($repo);
        $context   = $this->createMock(ExecutionContextInterface::class);

        $this->expectNoViolation($context);
        $validator->initialize($context);
        $validator->validate($record, new NoMultipleSpfTxt());
    }

    public function testNonSpfTxtRecordSkipsValidation(): void
    {
        $domain = new Domain();
        $record = $this->makeRecord(RecordType::TXT, 'somehost', 'some-verification-token', $domain);

        $repo = $this->createMock(DomainRecordRepository::class);
        $repo->expects($this->never())->method('hasOtherSpfTxtForHostname');

        $validator = new NoMultipleSpfTxtValidator($repo);
        $context   = $this->createMock(ExecutionContextInterface::class);

        $this->expectNoViolation($context);
        $validator->initialize($context);
        $validator->validate($record, new NoMultipleSpfTxt());
    }

    public function testNonTxtRecordSkipsValidation(): void
    {
        $domain = new Domain();
        $record = $this->makeRecord(RecordType::A, '@', '192.0.2.1', $domain);

        $repo = $this->createMock(DomainRecordRepository::class);
        $repo->expects($this->never())->method('hasOtherSpfTxtForHostname');

        $validator = new NoMultipleSpfTxtValidator($repo);
        $context   = $this->createMock(ExecutionContextInterface::class);

        $this->expectNoViolation($context);
        $validator->initialize($context);
        $validator->validate($record, new NoMultipleSpfTxt());
    }

    public function testNoDomainSkipsValidation(): void
    {
        $record = $this->makeRecord(RecordType::TXT, '@', 'v=spf1 -all');

        $repo = $this->createMock(DomainRecordRepository::class);
        $repo->expects($this->never())->method('hasOtherSpfTxtForHostname');

        $validator = new NoMultipleSpfTxtValidator($repo);
        $context   = $this->createMock(ExecutionContextInterface::class);

        $this->expectNoViolation($context);
        $validator->initialize($context);
        $validator->validate($record, new NoMultipleSpfTxt());
    }

    public function testSelfEditExcludesCurrentRecordId(): void
    {
        $domain = new Domain();
        $record = $this->makeRecord(RecordType::TXT, '@', 'v=spf1 -all', $domain, 7);

        $repo = $this->createMock(DomainRecordRepository::class);
        $repo->expects($this->once())
            ->method('hasOtherSpfTxtForHostname')
            ->with($domain, '@', 7)
            ->willReturn(false);

        $validator = new NoMultipleSpfTxtValidator($repo);
        $context   = $this->createMock(ExecutionContextInterface::class);

        $this->expectNoViolation($context);
        $validator->initialize($context);
        $validator->validate($record, new NoMultipleSpfTxt());
    }
}
