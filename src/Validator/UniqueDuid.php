<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
class UniqueDuid extends Constraint
{
    public string $message = 'This DUID is already assigned to another host.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
