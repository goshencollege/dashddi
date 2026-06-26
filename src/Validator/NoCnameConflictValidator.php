<?php

namespace App\Validator;

use App\Entity\DomainRecord;
use App\Enum\RecordType;
use App\Repository\DomainRecordRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class NoCnameConflictValidator extends ConstraintValidator
{
    public function __construct(private readonly DomainRecordRepository $repository) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NoCnameConflict) {
            throw new UnexpectedTypeException($constraint, NoCnameConflict::class);
        }

        if (!$value instanceof DomainRecord) {
            return;
        }

        $domain = $value->getDomain();
        if ($domain === null) {
            return;
        }

        $hostname  = $value->getHostname();
        $type      = $value->getType();
        $excludeId = $value->getId();

        if ($hostname === '@' && $type === RecordType::CNAME) {
            $this->context->buildViolation($constraint->cnameAtApexMessage)
                ->atPath('hostname')
                ->addViolation();
            return;
        }

        if ($type === RecordType::CNAME) {
            if ($this->repository->hasOtherRecordsForHostname($domain, $hostname, $excludeId)) {
                $this->context->buildViolation($constraint->cnameConflictMessage)
                    ->setParameter('{{ hostname }}', $hostname)
                    ->atPath('hostname')
                    ->addViolation();
            }
        } else {
            if ($this->repository->hasCnameForHostname($domain, $hostname, $excludeId)) {
                $this->context->buildViolation($constraint->otherConflictMessage)
                    ->setParameter('{{ hostname }}', $hostname)
                    ->setParameter('{{ type }}', $type->value)
                    ->atPath('hostname')
                    ->addViolation();
            }
        }
    }
}
