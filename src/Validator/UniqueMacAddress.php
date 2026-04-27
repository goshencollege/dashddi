<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
class UniqueMacAddress extends Constraint
{
    public string $message = 'This MAC address is already assigned to another interface.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
