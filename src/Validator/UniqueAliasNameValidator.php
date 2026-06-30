<?php

namespace App\Validator;

use App\Entity\DomainAlias;
use App\Repository\DomainAliasRepository;
use App\Repository\DomainRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class UniqueAliasNameValidator extends ConstraintValidator
{
    public function __construct(
        private readonly DomainAliasRepository $aliasRepo,
        private readonly DomainRepository      $domainRepo,
    ) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueAliasName) {
            throw new UnexpectedTypeException($constraint, UniqueAliasName::class);
        }

        if (!$value instanceof DomainAlias) {
            return;
        }

        $name = $value->getName();

        $existingAlias = $this->aliasRepo->findByName($name);
        if ($existingAlias !== null && $existingAlias->getId() !== $value->getId()) {
            $this->context
                ->buildViolation($constraint->messageDuplicateAlias)
                ->atPath('name')
                ->addViolation();
            return;
        }

        $existingDomain = $this->domainRepo->findOneBy(['name' => $name]);
        if ($existingDomain !== null) {
            $this->context
                ->buildViolation($constraint->messageDuplicateDomain)
                ->atPath('name')
                ->addViolation();
        }
    }
}
