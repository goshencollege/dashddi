<?php

namespace App\Validator;

use App\Entity\DomainRecord;
use App\Enum\RecordType;
use App\Repository\DomainRecordRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class NoMultipleSpfTxtValidator extends ConstraintValidator
{
    public function __construct(private readonly DomainRecordRepository $repository) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NoMultipleSpfTxt) {
            throw new UnexpectedTypeException($constraint, NoMultipleSpfTxt::class);
        }

        if (!$value instanceof DomainRecord) {
            return;
        }

        if ($value->getType() !== RecordType::TXT) {
            return;
        }

        if (stripos($value->getValue(), 'v=spf1') !== 0) {
            return;
        }

        $domain = $value->getDomain();
        if ($domain === null) {
            return;
        }

        if ($this->repository->hasOtherSpfTxtForHostname($domain, $value->getHostname(), $value->getId())) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ hostname }}', $value->getHostname())
                ->atPath('value')
                ->addViolation();
        }
    }
}
