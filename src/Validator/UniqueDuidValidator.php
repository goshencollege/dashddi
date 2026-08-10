<?php

namespace App\Validator;

use App\Entity\Host;
use App\Repository\HostRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class UniqueDuidValidator extends ConstraintValidator
{
    public function __construct(private readonly HostRepository $repo) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueDuid) {
            throw new UnexpectedTypeException($constraint, UniqueDuid::class);
        }

        if (!$value instanceof Host) {
            return;
        }

        $duid = $value->getDuid();

        if ($duid === null || $duid === '') {
            return;
        }

        $existing = $this->repo->findOneBy(['duid' => $duid]);

        if ($existing !== null && $existing->getId() !== $value->getId()) {
            $this->context
                ->buildViolation($constraint->message)
                ->atPath('duid')
                ->addViolation();
        }
    }
}
