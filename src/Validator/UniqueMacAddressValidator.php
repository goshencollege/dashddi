<?php

namespace App\Validator;

use App\Entity\NetworkInterface;
use App\Repository\NetworkInterfaceRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class UniqueMacAddressValidator extends ConstraintValidator
{
    public function __construct(private readonly NetworkInterfaceRepository $repo) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueMacAddress) {
            throw new UnexpectedTypeException($constraint, UniqueMacAddress::class);
        }

        if (!$value instanceof NetworkInterface) {
            return;
        }

        $mac = $value->getMacAddress();

        // All-zeros is the "MAC unknown" placeholder — duplicates allowed
        if ($mac === '00:00:00:00:00:00') {
            return;
        }

        $existing = $this->repo->findOneBy(['macAddress' => $mac]);

        if ($existing !== null && $existing->getId() !== $value->getId()) {
            $this->context
                ->buildViolation($constraint->message)
                ->atPath('macAddress')
                ->addViolation();
        }
    }
}
