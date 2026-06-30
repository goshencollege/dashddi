<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
class UniqueAliasName extends Constraint
{
    public string $messageDuplicateAlias  = 'This name is already in use as a domain alias.';
    public string $messageDuplicateDomain = 'This name is already registered as a domain.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
